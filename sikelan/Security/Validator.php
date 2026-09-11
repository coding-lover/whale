<?php

namespace Sikelan\Security;

/**
 * 流式数据验证器
 *
 * API 风格参考 Laravel Validator：
 *   $v = Validator::make($_POST, [
 *       'email' => 'required|email|max:255',
 *       'age'   => 'nullable|int|between:0,150',
 *   ]);
 *   if ($v->fails()) { ... $v->errors() ... }
 *   $data = $v->validated();
 *
 * 内置规则：
 *   required nullable int string email url regex:/pat/ min:n max:n
 *   between:min,max in:a,b,c not_in:a,b alpha alnum confirmed
 *
 * 错误消息默认中文，可通过 make() 第三参数自定义：
 *   ['email.required' => '邮箱必填', 'age.between' => '年龄必须 {min}-{max}']
 *
 * 协程安全：实例状态仅在单个验证流程中使用，无静态可变状态。
 */
class Validator
{
    /** 待验证数据 */
    private array $data;

    /** 规则定义 [字段 => 'rule1|rule2' | ['rule1', 'rule2']] */
    private array $rules;

    /** 自定义错误消息 [字段.规则 => 消息] */
    private array $messages;

    /** 验证结果错误 [字段 => [消息1, 消息2, ...]] */
    private array $errors = [];

    /** 是否已执行验证（避免重复跑 passes） */
    private bool $validated = false;

    /**
     * 静态工厂
     *
     * @param array  $data     待验证数据
     * @param array  $rules    规则 [字段 => 'rule1|rule2:arg' | ['rule1', 'rule2:arg']]
     * @param array  $messages 自定义消息 [字段.规则 => 消息模板]
     */
    public static function make(array $data, array $rules, array $messages = []): self
    {
        return new self($data, $rules, $messages);
    }

    public function __construct(array $data, array $rules, array $messages = [])
    {
        $this->data = $data;
        $this->rules = $rules;
        $this->messages = $messages;
    }

    /**
     * 执行验证，返回是否通过
     */
    public function passes(): bool
    {
        // 懒执行：第一次调用时跑全部规则；后续直接复用结果
        if (!$this->validated) {
            $this->validate();
            $this->validated = true;
        }
        return empty($this->errors);
    }

    /**
     * 是否验证失败（passes() 的反义）
     */
    public function fails(): bool
    {
        return !$this->passes();
    }

    /**
     * 获取所有错误
     *
     * @return array<string, list<string>> [字段 => [消息1, 消息2, ...]]
     */
    public function errors(): array
    {
        // 触发一次验证确保 errors 已填充
        $this->passes();
        return $this->errors;
    }

    /**
     * 获取通过验证的字段子集（仅包含 rules 中声明且 data 中存在的字段）
     *
     * 注意：返回原始值，未做类型转换；类型转换请配合 Request::input($key, $cast) 使用。
     */
    public function validated(): array
    {
        $result = [];
        foreach (array_keys($this->rules) as $field) {
            // array_key_exists 而非 isset：null 值也算"存在"（配合 nullable 规则）
            if (array_key_exists($field, $this->data)) {
                $result[$field] = $this->data[$field];
            }
        }
        return $result;
    }

    // ------------------------------------------------------------------
    //  内部实现
    // ------------------------------------------------------------------

    /**
     * 执行全部规则
     */
    private function validate(): void
    {
        $this->errors = [];
        foreach ($this->rules as $field => $ruleSpec) {
            // 规则统一为数组形式
            $rules = is_string($ruleSpec) ? explode('|', $ruleSpec) : (array) $ruleSpec;
            $value = array_key_exists($field, $this->data) ? $this->data[$field] : null;

            foreach ($rules as $rule) {
                $rule = trim($rule);
                if ($rule === '') {
                    continue;
                }
                // 解析 rule:param 形式
                $segments = explode(':', $rule, 2);
                $name = $segments[0];
                $param = $segments[1] ?? null;

                // nullable + null 值：后续所有规则跳过
                if ($name === 'nullable') {
                    if ($value === null) {
                        break; // 跳出该字段的全部剩余规则
                    }
                    continue;
                }

                // required 校验：null / 空字符串 / 空数组 都算未通过
                if ($name === 'required') {
                    if ($value === null || $value === '' || $value === []) {
                        $this->addError($field, 'required', $param);
                        // required 失败立即停止该字段其他规则（其他规则对空值无意义）
                        break;
                    }
                    continue;
                }

                // 后续规则：null 值跳过（除非上面有 required 已通过 / nullable 已跳过）
                if ($value === null) {
                    continue;
                }

                $this->checkRule($field, $value, $name, $param);
            }
        }
    }

    /**
     * 单条规则校验
     */
    private function checkRule(string $field, $value, string $rule, ?string $param): void
    {
        switch ($rule) {
            case 'int':
                // is_numeric 兼容 "123" / 123 / 12.5；强转后值应等于原值
                if (!is_numeric($value) || (int) $value != $value) {
                    $this->addError($field, $rule, $param);
                }
                break;

            case 'string':
                if (!is_string($value)) {
                    $this->addError($field, $rule, $param);
                }
                break;

            case 'email':
                if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    $this->addError($field, $rule, $param);
                }
                break;

            case 'url':
                if (filter_var($value, FILTER_VALIDATE_URL) === false) {
                    $this->addError($field, $rule, $param);
                }
                break;

            case 'regex':
                // regex:pattern 形式；pattern 中的 / 需要用户自行转义或用其他分隔符
                if ($param === null || @preg_match('/' . $param . '/', (string) $value) !== 1) {
                    $this->addError($field, $rule, $param);
                }
                break;

            case 'min':
                // 数值型：值不能小于；字符串型：长度不能小于
                if ($param === null) {
                    break;
                }
                if (is_numeric($value) && (float) $value < (float) $param) {
                    $this->addError($field, $rule, $param);
                } elseif (is_string($value) && mb_strlen($value) < (int) $param) {
                    $this->addError($field, $rule, $param);
                }
                break;

            case 'max':
                if ($param === null) {
                    break;
                }
                if (is_numeric($value) && (float) $value > (float) $param) {
                    $this->addError($field, $rule, $param);
                } elseif (is_string($value) && mb_strlen($value) > (int) $param) {
                    $this->addError($field, $rule, $param);
                }
                break;

            case 'between':
                // between:min,max
                if ($param === null) {
                    break;
                }
                [$min, $max] = array_pad(explode(',', $param, 2), 2, null);
                if ($min === null || $max === null) {
                    break;
                }
                if (is_numeric($value)) {
                    if ((float) $value < (float) $min || (float) $value > (float) $max) {
                        $this->addError($field, $rule, $param);
                    }
                } elseif (is_string($value)) {
                    $len = mb_strlen($value);
                    if ($len < (int) $min || $len > (int) $max) {
                        $this->addError($field, $rule, $param);
                    }
                }
                break;

            case 'in':
                // in:a,b,c —— 严格匹配列表中任一
                if ($param === null) {
                    break;
                }
                $allowed = explode(',', $param);
                if (!in_array($value, $allowed, true)) {
                    $this->addError($field, $rule, $param);
                }
                break;

            case 'not_in':
                if ($param === null) {
                    break;
                }
                $forbidden = explode(',', $param);
                if (in_array($value, $forbidden, true)) {
                    $this->addError($field, $rule, $param);
                }
                break;

            case 'alpha':
                if (!preg_match('/^[a-zA-Z]+$/', (string) $value)) {
                    $this->addError($field, $rule, $param);
                }
                break;

            case 'alnum':
                if (!preg_match('/^[a-zA-Z0-9]+$/', (string) $value)) {
                    $this->addError($field, $rule, $param);
                }
                break;

            case 'confirmed':
                // 需要同字段加 _confirmation 后缀的字段值相同（用于密码二次确认）
                $confirmField = $field . '_confirmation';
                $confirm = $this->data[$confirmField] ?? null;
                if ($value !== $confirm) {
                    $this->addError($field, $rule, $param);
                }
                break;

            default:
                // 未知规则：忽略（避免误判业务侧自定义规则）
                break;
        }
    }

    /**
     * 记录错误（优先用自定义消息，否则用默认中文消息）
     *
     * @param string      $field 字段名
     * @param string      $rule  规则名
     * @param string|null $param 规则参数（如 min:5 的 "5"）
     */
    private function addError(string $field, string $rule, ?string $param): void
    {
        $key = "{$field}.{$rule}";
        if (isset($this->messages[$key])) {
            $message = $this->messages[$key];
        } else {
            $message = $this->defaultMessage($field, $rule, $param);
        }
        $this->errors[$field][] = $message;
    }

    /**
     * 生成默认中文错误消息
     */
    private function defaultMessage(string $field, string $rule, ?string $param): string
    {
        $display = $field;
        switch ($rule) {
            case 'required':
                return "{$display} 是必填项";
            case 'int':
                return "{$display} 必须是整数";
            case 'string':
                return "{$display} 必须是字符串";
            case 'email':
                return "{$display} 不是有效的邮箱地址";
            case 'url':
                return "{$display} 不是有效的 URL";
            case 'regex':
                return "{$display} 格式不正确";
            case 'min':
                return "{$display} 不能小于 {$param}";
            case 'max':
                return "{$display} 不能大于 {$param}";
            case 'between':
                [$min, $max] = array_pad(explode(',', (string) $param, 2), 2, '');
                return "{$display} 必须在 {$min} 和 {$max} 之间";
            case 'in':
                return "{$display} 的值不在允许范围内";
            case 'not_in':
                return "{$display} 的值在禁止范围内";
            case 'alpha':
                return "{$display} 只能包含字母";
            case 'alnum':
                return "{$display} 只能包含字母和数字";
            case 'confirmed':
                return "{$display} 两次输入不一致";
            default:
                return "{$display} 校验失败";
        }
    }
}
