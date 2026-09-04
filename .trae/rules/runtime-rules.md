---
alwaysApply: false
globs: app/runtime/**
---
# 运行时静态数据目录约定（编辑 app/runtime/** 时加载）

## 位置
- 物理路径：`app/runtime/`
- PHP 常量：`RUNTIME_PATH = APP_PATH . '/runtime'`（`sikelan/Core/constants.php`）
- 框架启动时自动 `mkdir(RUNTIME_PATH, 0755, true)`

## 子目录划分
| 子目录 | 进 Git？ | 用途 |
|--------|---------|------|
| `app/runtime/static/` | ✅ 必须 | 运行时依赖的静态数据（白名单 JSON、pair 映射、种子 CSV） |
| `app/runtime/trader/data/` | 🟡 小体积可进 | 回测种子 OHLCV（<5MB 可共享；大数据集走对象存储） |
| `app/runtime/trader/output/` | ❌ 绝对不进 | 回测导出 JSON/CSV（每次重生成） |
| `app/runtime/cache/` | ❌ 不进 | Config 编译缓存、路由缓存、类映射（启动自动重建） |
| `app/runtime/*.pid` | ❌ 不进 | Swoole 服务 PID 文件 |

## 禁止反模式
- 硬编码字符串路径 `/runtime/xxx` → 必须用 `RUNTIME_PATH` 常量
- 相对路径 `'../../runtime'` → CLI/CWD 不一致会找错

## 新增运行时静态数据 3 步
1. **选址**：依赖的输入数据放 `static/` 或 `trader/data/`；输出放 `cache/` 或 `trader/output/`
2. **加 .gitkeep / .gitignore**：空目录放 `.gitkeep`；大体积文件在 `.gitignore` 加负向规则如 `!trader/data/btc_5m.csv`
3. **用常量引用**：`file_get_contents(RUNTIME_PATH . '/static/quote/whitelist.json')`
