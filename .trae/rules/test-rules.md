---
alwaysApply: false
globs: tests/**
---
# 测试约定（编辑 tests/** 时加载）

## 目录划分（按被测代码归属严格分）
| 目录 | 被测层 | 命名空间映射 |
|------|--------|-------------|
| `tests/stest/` | 框架核心 `sikelan/` | `Sikelan\Tests\stest` |
| `tests/atest/` | 应用层 `app/` Controllers/Services/Tasks/Models | `Sikelan\Tests\atest` |
| `tests/trader_test/` | `app/Services/Trader/` + `App\Services\Exchanges\` | `Sikelan\Tests\trader_test` |

## 规范
- 文件命名：`{被测类名}Test.php`
- 方法命名：`test{场景}`（camelCase，多单词描述）
- PHPUnit ^9.5，入口 `composer test`
- Bootstrap：`tests/bootstrap.php`（已加载 autoload + 常量 + `env()` 兜底）
- 单独跑：`./vendor/bin/phpunit tests/trader_test/ --testdox`
- 框架层跑：`composer test:stest`

## Trader 测试特殊约定
- 需 trader 扩展的用例开头加 `requireTraderOrSkip()`
- 协程安全测试放在 `tests/trader_test/` 下，标记 `@requires extension swoole`

## Exchange 适配器测试
- 测试 mock `request()` 方法后验证 URL/签名/参数拼接，避免真实网络
- 断言签名时验证 URL 中包含 `signature=` 参数 + 正确 HMAC 值
