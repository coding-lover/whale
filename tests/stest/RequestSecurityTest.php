<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Http\Request;

/**
 * Request 安全便利方法单元测试
 *
 * 覆盖：
 * - input() 三源合并查找 + 净化 + 类型转换
 * - getInt/getString/getBool/getFloat/getEmail/getUrl/getAlpha/getAlnum
 * - has() / only() / except() / all()
 * - setRouteParams() / getRouteParams()
 * - 净化 null byte / 控制字符
 */
class RequestSecurityTest extends TestCase
{
    /**
     * 构造带预设 query + post 参数的 Request
     */
    private function makeRequest(array $query = [], array $post = [], array $route = []): Request
    {
        $request = new Request('GET', '/', [], null);
        // 用反射注入 query/post/cookies（构造器未暴露 setter）
        $refl = new \ReflectionClass($request);
        $q = $refl->getProperty('queryParams');
        $q->setAccessible(true);
        $q->setValue($request, $query);

        $p = $refl->getProperty('postParams');
        $p->setAccessible(true);
        $p->setValue($request, $post);

        // 路由参数走公开方法
        if ($route !== []) {
            $request->setRouteParams($route);
        }
        return $request;
    }

    // ========== setRouteParams / getRouteParams ==========

    public function testSetRouteParams_GetRouteParamsRoundTrip()
    {
        $req = new Request('GET', '/');
        $this->assertSame([], $req->getRouteParams());
        $req->setRouteParams(['id' => '123']);
        $this->assertSame(['id' => '123'], $req->getRouteParams());
    }

    // ========== input() ==========

    public function testInput_FallsBackFromQueryToPostToRouteToDefault()
    {
        $req = $this->makeRequest(['name' => 'from-query']);
        $this->assertSame('from-query', $req->input('name'));

        $req = $this->makeRequest([], ['name' => 'from-post']);
        $this->assertSame('from-post', $req->input('name'));

        $req = $this->makeRequest([], [], ['name' => 'from-route']);
        $this->assertSame('from-route', $req->input('name'));

        $req = $this->makeRequest();
        $this->assertNull($req->input('nonexistent'));
        $this->assertSame('default', $req->input('nonexistent', 'default'));
    }

    public function testInput_QueryOverridesPostOverridesRoute()
    {
        $req = $this->makeRequest(
            ['name' => 'query'],
            ['name' => 'post'],
            ['name' => 'route']
        );
        $this->assertSame('query', $req->input('name'));
    }

    public function testInput_CleanNullByte()
    {
        $req = $this->makeRequest(['name' => "a\0b\0c"]);
        $this->assertSame('abc', $req->input('name'));
    }

    public function testInput_CleanControlChars()
    {
        $req = $this->makeRequest(['name' => "a\x01b\x1Fc"]);
        $this->assertSame('abc', $req->input('name'));
    }

    public function testInput_PreservesTabNewline()
    {
        $req = $this->makeRequest(['content' => "line1\ttab\nline2\r"]);
        $this->assertSame("line1\ttab\nline2\r", $req->input('content'));
    }

    public function testInput_CastToInt()
    {
        $req = $this->makeRequest(['age' => '25']);
        $this->assertSame(25, $req->input('age', null, 'int'));
    }

    public function testInput_CastToIntInvalidReturnsDefault()
    {
        $req = $this->makeRequest(['age' => 'not-int']);
        $this->assertSame(0, $req->input('age', 0, 'int'));
    }

    public function testInput_CastArrayCleanedRecursively()
    {
        $req = $this->makeRequest(['tags' => ["a\0b", "c\x01d"]]);
        $result = $req->input('tags');
        $this->assertSame(['ab', 'cd'], $result);
    }

    // ========== getInt ==========

    public function testGetInt_ValidNumericString()
    {
        $req = $this->makeRequest(['id' => '123']);
        $this->assertSame(123, $req->getInt('id'));
    }

    public function testGetInt_MissingFieldReturnsDefault()
    {
        $req = $this->makeRequest();
        $this->assertSame(99, $req->getInt('id', 99));
    }

    public function testGetInt_InvalidReturnsDefault()
    {
        $req = $this->makeRequest(['id' => 'abc']);
        $this->assertSame(0, $req->getInt('id'));
    }

    public function testGetInt_FromRouteParam()
    {
        $req = $this->makeRequest([], [], ['id' => '456']);
        $this->assertSame(456, $req->getInt('id'));
    }

    // ========== getString ==========

    public function testGetString_ReturnsString()
    {
        $req = $this->makeRequest(['name' => 123]);
        $this->assertSame('123', $req->getString('name'));
    }

    public function testGetString_MissingReturnsDefault()
    {
        $req = $this->makeRequest();
        $this->assertSame('guest', $req->getString('name', 'guest'));
    }

    public function testGetString_ArrayReturnsDefault()
    {
        $req = $this->makeRequest(['tags' => ['a', 'b']]);
        $this->assertSame('', $req->getString('tags'));
    }

    // ========== getBool ==========

    public function testGetBool_TruthyString()
    {
        $req = $this->makeRequest(['flag' => 'true']);
        $this->assertTrue($req->getBool('flag'));
        $req = $this->makeRequest(['flag' => 'yes']);
        $this->assertTrue($req->getBool('flag'));
        $req = $this->makeRequest(['flag' => '1']);
        $this->assertTrue($req->getBool('flag'));
        $req = $this->makeRequest(['flag' => 'on']);
        $this->assertTrue($req->getBool('flag'));
    }

    public function testGetBool_FalsyString()
    {
        $req = $this->makeRequest(['flag' => 'false']);
        $this->assertFalse($req->getBool('flag'));
        $req = $this->makeRequest(['flag' => 'off']);
        $this->assertFalse($req->getBool('flag'));
        $req = $this->makeRequest(['flag' => '0']);
        $this->assertFalse($req->getBool('flag'));
    }

    public function testGetBool_MissingReturnsDefault()
    {
        $req = $this->makeRequest();
        $this->assertTrue($req->getBool('flag', true));
    }

    // ========== getFloat ==========

    public function testGetFloat_Valid()
    {
        $req = $this->makeRequest(['price' => '12.5']);
        $this->assertSame(12.5, $req->getFloat('price'));
    }

    public function testGetFloat_InvalidReturnsDefault()
    {
        $req = $this->makeRequest(['price' => 'not-a-number']);
        $this->assertSame(0.0, $req->getFloat('price'));
    }

    // ========== getEmail / getUrl ==========

    public function testGetEmail_Valid()
    {
        $req = $this->makeRequest(['e' => 'user@example.com']);
        $this->assertSame('user@example.com', $req->getEmail('e'));
    }

    public function testGetEmail_InvalidReturnsNull()
    {
        $req = $this->makeRequest(['e' => 'not-email']);
        $this->assertNull($req->getEmail('e'));
    }

    public function testGetEmail_MissingReturnsNull()
    {
        $req = $this->makeRequest();
        $this->assertNull($req->getEmail('e'));
    }

    public function testGetUrl_Valid()
    {
        $req = $this->makeRequest(['u' => 'https://example.com']);
        $this->assertSame('https://example.com', $req->getUrl('u'));
    }

    public function testGetUrl_InvalidReturnsNull()
    {
        $req = $this->makeRequest(['u' => 'javascript:alert(1)']);
        $this->assertNull($req->getUrl('u'));
    }

    // ========== getAlpha / getAlnum ==========

    public function testGetAlpha_StripsNonLetters()
    {
        $req = $this->makeRequest(['code' => 'Hello123!']);
        $this->assertSame('Hello', $req->getAlpha('code'));
    }

    public function testGetAlnum_StripsNonAlphanumeric()
    {
        $req = $this->makeRequest(['code' => 'Hello123!']);
        $this->assertSame('Hello123', $req->getAlnum('code'));
    }

    // ========== has() ==========

    public function testHas_TrueWhenFieldExistsInQuery()
    {
        $req = $this->makeRequest(['name' => 'alice']);
        $this->assertTrue($req->has('name'));
    }

    public function testHas_TrueWhenFieldExistsInPost()
    {
        $req = $this->makeRequest([], ['name' => 'alice']);
        $this->assertTrue($req->has('name'));
    }

    public function testHas_TrueWhenFieldExistsInRoute()
    {
        $req = $this->makeRequest([], [], ['name' => 'alice']);
        $this->assertTrue($req->has('name'));
    }

    public function testHas_FalseWhenFieldMissing()
    {
        $req = $this->makeRequest();
        $this->assertFalse($req->has('nonexistent'));
    }

    public function testHas_TrueEvenWhenValueIsNull()
    {
        // array_key_exists 而非 isset——null 值也算存在
        $req = $this->makeRequest(['name' => null]);
        $this->assertTrue($req->has('name'));
    }

    // ========== only() / except() ==========

    public function testOnly_ReturnsSpecifiedKeys()
    {
        $req = $this->makeRequest(['a' => 1, 'b' => 2, 'c' => 3]);
        $result = $req->only(['a', 'c']);
        $this->assertSame(['a' => 1, 'c' => 3], $result);
    }

    public function testOnly_MissingKeysAreNull()
    {
        $req = $this->makeRequest(['a' => 1]);
        $result = $req->only(['a', 'b']);
        $this->assertSame(['a' => 1, 'b' => null], $result);
    }

    public function testExcept_RemovesSingleKey()
    {
        $req = $this->makeRequest(['a' => 1, 'b' => 2, 'c' => 3]);
        $result = $req->except(['b']);
        $this->assertSame(['a' => 1, 'c' => 3], $result);
    }

    public function testExcept_RemovesMultipleKeys()
    {
        $req = $this->makeRequest(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]);
        $result = $req->except(['a', 'c']);
        $this->assertSame(['b' => 2, 'd' => 4], $result);
    }

    // ========== all() ==========

    public function testAll_MergesThreeSources()
    {
        $req = $this->makeRequest(['a' => 1], ['b' => 2], ['c' => 3]);
        $all = $req->all();
        // 合并顺序取决于实现（query > post > route 优先级），按 key 排序后比较内容
        ksort($all);
        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $all);
    }

    public function testAll_AppliesCleanToAllValues()
    {
        $req = $this->makeRequest(['a' => "x\0y"]);
        $all = $req->all();
        $this->assertSame(['a' => 'xy'], $all);
    }

    public function testAll_QueryOverridesPost()
    {
        $req = $this->makeRequest(['name' => 'from-query'], ['name' => 'from-post']);
        $all = $req->all();
        $this->assertSame('from-query', $all['name']);
    }
}
