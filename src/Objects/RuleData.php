<?php

declare(strict_types=1);

namespace Becommerce\PostmanGenerator\Objects;

use Becommerce\PostmanGenerator\Config\LaravelPostman;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationRuleParser;

class RuleData implements Arrayable
{
    public function __construct(private array $rule, private LaravelPostman $config) 
    {
    }
    
    public function toArray(): array
    {
        return [
            'key' => $this->rule['name'],
            'value' => $this->config->getFormDataRule($this->rule['name']),
            'description' => $this->parseRulesIntoHumanReadable($this->rule['name'], $this->rule['description']),
            'type' => $this->rule['type'] ?? 'text',
        ];
    }

    /**
     * Process a rule set and utilize the Validator to create human-readable descriptions
     * to help users provide valid data.
     *
     * @param mixed $attribute
     * @param mixed $rules
     */
    protected function parseRulesIntoHumanReadable($attribute, $rules): string
    {
        // ... bail if user has asked for non interpreted strings:
        if (! $this->config->rulesToHumanReadableEnabled()) {
            return is_array($rules)
                ? implode(', ', $rules)
                : $this->safelyStringifyClassBasedRule($rules);
        }

        /*
         * An object based rule is presumably a Laravel default class based rule or one that implements to Illuminate
         * Rule interface. Let's try to safely access the string representation...
         */
        if (is_object($rules)) {
            $rules = [$this->safelyStringifyClassBasedRule($rules)];
        }

        /*
         * Handle string based rules (e.g. required|string|max:30)
         */
        if (is_array($rules)) {
            $validator = Validator::make([], [$attribute => implode('|', $rules)]);

            foreach ($rules as $rule) {
                [$rule, $parameters] = ValidationRuleParser::parse($rule);

                $validator->addFailure($attribute, $rule, $parameters);
            }

            $messages = $validator->getMessageBag()->toArray()[$attribute];

            if (is_array($messages)) {
                $messages = $this->handleEdgeCases($messages);
            }

            return implode(', ', is_array($messages) ? $messages : $messages->toArray());
        }
        
        return '';
    }

    /**
     * Certain fields are not handled via the normal throw failure method in the validator
     * We need to add a human-readable message.
     *
     * @param array<string, string> $messages
     *
     * @return array<string, string>
     */
    protected function handleEdgeCases(array $messages): array
    {
        foreach ($messages as $key => $message) {
            $messages[$key] = match ($message) {
                'validation.nullable' => '(Nullable)',
                'validation.sometimes' => '(Optional)',
                default => $message,
            };
        }

        return $messages;
    }

    /**
     * In this case we have received what is most likely a Rule Object but are not certain.
     */
    protected function safelyStringifyClassBasedRule(mixed $probableRule): string
    {
        if (is_object($probableRule)
            && (
                is_subclass_of($probableRule, Rule::class)
                || method_exists($probableRule, '__toString'))
        ) {
            return (string) $probableRule;
        }

        return '';
    }
}
