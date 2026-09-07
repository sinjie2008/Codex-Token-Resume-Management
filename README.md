# Codex Auto Resume

Codex Auto Resume 是一个本地 Windows 工具：它从真实 Codex rollout 与 SQLite 状态读取 session、usage 和 rate-limit 事件，把受限 session 持久化进 MySQL，在本地等待 reset，然后通过原始 Session ID 安全执行 `codex exec resume`。

## 为什么这样设计

- 等待只读取本地时钟、MySQL、Codex SQLite/JSONL，不会为了“额度恢复了吗”反复调用模型。
- Session ID 是唯一权威标识；title 和 display name 只负责让人看懂。
- Resume prompt 通过 stdin 传入，进程使用参数数组，不把用户输入拼接进 shell。
- `GET_LOCK` 保证单一 worker，数据库条件更新保证单一 session 只能取得一次 resume lock。
- 新 turn、新 user activity、active writer 或 in-progress writer lock 都会阻止重复 resume。
- reset 已过 10 分钟，或缺少 reset 且 limit 事件已超过 6 小时，worker 会要求人工复核；不会拿旧数据库记录直接续跑。
- Laragon 的 `Procfile` 是本机已有的原生自启机制，不使用 Windows Task Scheduler 或 Windows Service。

## 本机已核验的 Codex 数据源

- `C:\Users\sIn.jie\.codex\state_5.sqlite` 的 `threads` 表提供 session ID、title/name、cwd、rollout path、更新时间。
- `C:\Users\sIn.jie\.codex\thread_history_1.sqlite` 的 `thread_turns` 提供 turn status 与 `usageLimitExceeded` 结构化错误。
- `C:\Users\sIn.jie\.codex\sessions\YYYY\MM\DD\rollout-*.jsonl` 提供 ordinal、turn、activity 与 `token_count.rate_limits`。
- `C:\Users\sIn.jie\.codex\thread-writer-locks` 配合 `thread_turns.status=inProgress` 判断正在写入；空 lock 文件本身不会被当成充分证据。
- 当前桌面直连二进制支持 `codex exec resume [SESSION_ID] [PROMPT]`、stdin prompt `-` 与 `--json`。

## 安装

Laragon 的 MySQL、Apache 与 PHP 启动后，在项目根执行：

```powershell
powershell -ExecutionPolicy Bypass -File scripts\install.ps1
```

有 MySQL 密码时：

```powershell
powershell -ExecutionPolicy Bypass -File scripts\install.ps1 -DbUser root -DbPassword "your-password" -ForceConfig
```

安装器会：

1. 核验 `php.exe`、`mysql.exe`、直连 `codex.exe` 与 Codex state DB。
2. 创建 `codex_auto_resume` 数据库并应用 [database/schema.sql](database/schema.sql)。
3. 生成本地 `.env`；真实凭据不进入版本控制。
4. 只在 `C:\laragon\usr\Procfile` 追加带 managed marker 的 worker 条目，并保留一次备份。
5. 运行一次 `worker.php --once --dry-run`，不会调用模型。

不想修改 Laragon Procfile 时，加 `-SkipLaragonProcfile`。

## 启动与访问

Laragon 下一次启动会根据 Procfile 启动 worker。现在立即启动可执行：

```powershell
powershell -ExecutionPolicy Bypass -File scripts\start-worker.ps1
```

Dashboard：

```text
http://codex_token_resume_management.test/
```

浏览器关闭不影响 worker。应用只允许 `.env` 中 `APP_ALLOWED_IPS` 的本地来源访问。

## 工作流

```text
rollout / thread_turns reports usage limit
  -> WAITING_FOR_RESET
  -> local MySQL + clock wait
  -> verify no newer turn/activity
  -> verify no in-progress writer
  -> acquire atomic resume lock
  -> codex.exe exec resume --json --skip-git-repo-check <SESSION_ID> -
  -> success: COMPLETED
  -> active writer: ACTIVE_ELSEWHERE + local backoff
  -> still limited: RETRY_WAIT + reported reset/backoff
```

`Resume Now` 只把 reset 时间设为现在并交给 worker，仍然遵守 cooldown、外部 activity、writer 和 atomic lock 检查。

`Delete` 只删除当前 Auto Resume 队列记录，并保留 rollout 扫描游标，避免同一个旧限流事件立刻重放。之后若同一 Codex session 发生新的限流事件，worker 会自动重新建立队列记录。原 Codex 对话和 rollout 不会被删除。

## 状态

- `WATCHING`: 正常监控，没有待恢复 limit。
- `WAITING_FOR_RESET`: 已确认受限，等待已报告 reset。
- `RESUMING`: worker 已取得原子锁并运行同一 Session ID。
- `RETRY_WAIT`: 仍受限或暂时失败，按本地 backoff 等待。
- `ACTIVE_ELSEWHERE`: Codex Desktop/CLI 正在写入，暂停抢占。
- `RESUMED_EXTERNALLY`: limit 后发现新的本地 turn/activity，自动恢复被阻止。
- `COMPLETED`: worker 发起的 resume 进程成功完成。
- `CANCELLED`: 此 session 的自动恢复已禁用。
- `ERROR`: session 已不存在等不可安全重试错误。

## 验证

不调用模型的检查：

```powershell
php tests\run.php
php tests\mysql_integration.php
php worker.php --once --dry-run
```

`tests/run.php` 使用临时 Codex SQLite/rollout fixture 验证结构化 limit、文字 reset time、null premium snapshot、active writer、命令参数与错误分类。`tests/mysql_integration.php` 在真实 MySQL schema 中验证自动入队、每个 session 独立的 usage context、atomic lock、外部恢复、删除后新限流自动重建队列，然后只清理本次随机 fixture。

真实 rate-limit 无法安全地按需制造，因此测试会明确区分模拟事件与真实本地读取；不会伪造一次 live Codex resume 成功。
