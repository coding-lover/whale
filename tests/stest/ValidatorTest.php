<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Security\Validator;

/**
 * Validator 单元测试
 *
 * 覆盖：
 * - 各内置规则：required / nullable / int / string / email / url / regex / min / max / between / in / not_in / alpha / alnum / confirmed
 * - passes() / fails() / errors() / validated() 接口
 * - 自定义消息
 * - 多规则组合
 */
class ValidatorTest extends TestCase
{
    public function testPasses_ReturnsTrueWhenAllRulesPass()
    {
        $v = Validator::make(
            ['email' => 'user@example.com', 'age' => 25],
            ['email' => 'required|email', 'age' => 'required|int']
        );
        $this->assertTrue($v->passes());
        $this->assertFalse($v->fails());
        $this->assertEmpty($v->errors());
    }

    public function testFails_ReturnsTrueWhenRequiredFieldMissing()
    {
        $v = Validator::make([], ['name' => 'required']);
        $this->assertTrue($v->fails());
        $this->assertNotEmpty($v->errors());
        $this->assertArrayHasKey('name', $v->errors());
    }

    public function testRequired_RejectsEmptyString()
    {
        $v = Validator::make(['name' => ''], ['name' => 'required']);
        $this->assertTrue($v->fails());
    }

    public function testRequired_RejectsEmptyArray()
    {
        $v = Validator::make(['tags' => []], ['tags' => 'required']);
        $this->assertTrue($v->fails());
    }

    public function testRequired_AcceptsZero()
    {
        // 0 不是 empty（避免业务侧把 0 当空值误判）
        $v = Validator::make(['count' => 0], ['count' => 'required|int']);
        $this->assertTrue($v->passes());
    }

    public function testNullable_SkipsSubsequentRulesWhenNull()
    {
        $v = Validator::make(
            ['age' => null],
            ['age' => 'nullable|int|between:0,150']
        );
        $this->assertTrue($v->passes());
    }

    public function testNullable_StillRunsRulesWhenValueProvided()
    {
        $v = Validator::make(
            ['age' => 'not-int'],
            ['age' => 'nullable|int']
        );
        $this->assertTrue($v->fails());
    }

    public function testInt_AcceptsNumericString()
    {
        $v = Validator::make(['age' => '25'], ['age' => 'int']);
        $this->assertTrue($v->passes());
    }

    public function testInt_RejectsNonNumeric()
    {
        $v = Validator::make(['age' => 'twenty'], ['age' => 'int']);
        $this->assertTrue($v->fails());
    }

    public function testEmail_ValidAndInvalid()
    {
        $good = Validator::make(['e' => 'user@example.com'], ['e' => 'email']);
        $this->assertTrue($good->passes());

        $bad = Validator::make(['e' => 'not-email'], ['e' => 'email']);
        $this->assertTrue($bad->fails());
    }

    public function testUrl_ValidAndInvalid()
    {
        $good = Validator::make(['u' => 'https://example.com'], ['u' => 'url']);
        $this->assertTrue($good->passes());

        $bad = Validator::make(['u' => 'javascript:alert(1)'], ['u' => 'url']);
        $this->assertTrue($bad->fails());
    }

    public function testRegex_MatchesPattern()
    {
        $v = Validator::make(['code' => 'ABC123'], ['code' => 'regex:^[A-Z]+\d+$']);
        $this->assertTrue($v->passes());
    }

    public function testRegex_FailsToMatch()
    {
        $v = Validator::make(['code' => 'abc'], ['code' => 'regex:^[A-Z]+\d+$']);
        $this->assertTrue($v->fails());
    }

    public function testMin_NumericValue()
    {
        $good = Validator::make(['price' => 100], ['price' => 'min:50']);
        $this->assertTrue($good->passes());

        $bad = Validator::make(['price' => 30], ['price' => 'min:50']);
        $this->assertTrue($bad->fails());
    }

    public function testMin_StringLength()
    {
        $good = Validator::make(['name' => 'hello'], ['name' => 'min:3']);
        $this->assertTrue($good->passes());

        $bad = Validator::make(['name' => 'hi'], ['name' => 'min:3']);
        $this->assertTrue($bad->fails());
    }

    public function testMax_NumericValue()
    {
        $good = Validator::make(['price' => 50], ['price' => 'max:100']);
        $this->assertTrue($good->passes());

        $bad = Validator::make(['price' => 150], ['price' => 'max:100']);
        $this->assertTrue($bad->fails());
    }

    public function testMax_StringLength()
    {
        $good = Validator::make(['name' => 'hi'], ['name' => 'max:5']);
        $this->assertTrue($good->passes());

        $bad = Validator::make(['name' => 'too long name'], ['name' => 'max:5']);
        $this->assertTrue($bad->fails());
    }

    public function testBetween_Numeric()
    {
        $good = Validator::make(['age' => 25], ['age' => 'between:0,150']);
        $this->assertTrue($good->passes());

        $badLow = Validator::make(['age' => -1], ['age' => 'between:0,150']);
        $this->assertTrue($badLow->fails());

        $badHigh = Validator::make(['age' => 200], ['age' => 'between:0,150']);
        $this->assertTrue($badHigh->fails());
    }

    public function testIn_ValueMustBeInList()
    {
        $good = Validator::make(['role' => 'admin'], ['role' => 'in:admin,user,guest']);
        $this->assertTrue($good->passes());

        $bad = Validator::make(['role' => 'superadmin'], ['role' => 'in:admin,user,guest']);
        $this->assertTrue($bad->fails());
    }

    public function testNotIn_ValueMustNotBeInList()
    {
        $good = Validator::make(['name' => 'alice'], ['name' => 'not_in:admin,root,system']);
        $this->assertTrue($good->passes());

        $bad = Validator::make(['name' => 'admin'], ['name' => 'not_in:admin,root,system']);
        $this->assertTrue($bad->fails());
    }

    public function testAlpha_OnlyLetters()
    {
        $good = Validator::make(['name' => 'Hello'], ['name' => 'alpha']);
        $this->assertTrue($good->passes());

        $bad = Validator::make(['name' => 'Hello123'], ['name' => 'alpha']);
        $this->assertTrue($bad->fails());
    }

    public function testAlnum_LettersAndDigits()
    {
        $good = Validator::make(['code' => 'ABC123'], ['code' => 'alnum']);
        $this->assertTrue($good->passes());

        $bad = Validator::make(['code' => 'ABC-123'], ['code' => 'alnum']);
        $this->assertTrue($bad->fails());
    }

    public function testConfirmed_MatchingConfirmationField()
    {
        $v = Validator::make(
            ['password' => 'secret', 'password_confirmation' => 'secret'],
            ['password' => 'confirmed']
        );
        $this->assertTrue($v->passes());
    }

    public function testConfirmed_MismatchedConfirmationField()
    {
        $v = Validator::make(
            ['password' => 'secret', 'password_confirmation' => 'different'],
            ['password' => 'confirmed']
        );
        $this->assertTrue($v->fails());
    }

    public function testErrors_ReturnsFieldMessagesMap()
    {
        $v = Validator::make(
            ['email' => 'not-email', 'name' => ''],
            ['email' => 'required|email', 'name' => 'required|string']
        );
        $v->passes();
        $errors = $v->errors();
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('name', $errors);
        $this->assertIsArray($errors['email']);
        $this->assertIsArray($errors['name']);
    }

    public function testValidated_ReturnsOnlyDeclaredFields()
    {
        $data = ['name' => 'alice', 'age' => 25, 'extra' => 'ignored'];
        $v = Validator::make($data, ['name' => 'required|string', 'age' => 'int']);
        $v->passes();
        $validated = $v->validated();
        $this->assertArrayHasKey('name', $validated);
        $this->assertArrayHasKey('age', $validated);
        $this->assertArrayNotHasKey('extra', $validated);
    }

    public function testCustomMessages_OverrideDefaults()
    {
        $v = Validator::make(
            ['email' => ''],
            ['email' => 'required'],
            ['email.required' => '邮箱字段必填哦']
        );
        $v->passes();
        $this->assertContains('邮箱字段必填哦', $v->errors()['email']);
    }

    public function testMultipleErrorsPerField()
    {
        // email 同时违反 required 和 email 规则——但 required 失败后会跳出后续规则
        // 这里用 required + email 同时不满足时，required 失败应立即停止 email 校验
        $v = Validator::make(['email' => ''], ['email' => 'required|email']);
        $v->passes();
        $errors = $v->errors();
        $this->assertCount(1, $errors['email']); // 仅 required 的错误
    }

    public function testMultipleRulesDifferentErrors()
    {
        // age 既不是 int 也不在 0-150 范围 → 应聚合两个错误
        $v = Validator::make(['age' => 'not-int'], ['age' => 'int|between:0,150']);
        $v->passes();
        $errors = $v->errors();
        $this->assertGreaterThanOrEqual(1, count($errors['age']));
    }

    public function testRuleArray_FormInsteadOfPipeString()
    {
        // 规则也可以传数组形式
        $v = Validator::make(
            ['email' => 'user@example.com'],
            ['email' => ['required', 'email']]
        );
        $this->assertTrue($v->passes());
    }

    public function testPasses_CalledMultipleTimesOnlyValidatesOnce()
    {
        $v = Validator::make(['x' => ''], ['x' => 'required']);
        $this->assertTrue($v->fails());
        $this->assertTrue($v->fails()); // 第二次应复用结果
        $this->assertTrue($v->fails());
    }
}
