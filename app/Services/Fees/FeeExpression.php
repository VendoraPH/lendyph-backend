<?php

namespace App\Services\Fees;

use Brick\Math\BigRational;

/**
 * A Formula fee written as an expression (`fees.formula_expression`), parsed
 * once and evaluated with exact rational maths. The browser never evaluates
 * one: it asks POST /fees/formula-check.
 *
 * ## Grammar
 *
 *     expression := term (("+" | "-" | "−") term)*
 *     term       := unary (("*" | "×" | "/" | "÷") unary)*
 *     unary      := ("-" | "−") unary | postfix
 *     postfix    := primary "%"?          (÷ 100: 2.5% = 0.025)
 *     primary    := number | variable | "(" expression ")"
 *
 * Numbers are digits with an optional decimal part (`12`, `2.5`). Variables
 * ({@see self::VARIABLES}) match case-insensitively, with any run of spaces
 * counting as one. Nothing else is accepted: no functions, powers, commas or
 * other names, and nothing is ever handed to `eval`.
 *
 * ## Refused at parse (save), each in words
 *
 * An empty formula, a stray character, an unknown name, unbalanced brackets,
 * a missing operand or operator, more than 255 characters, 64 parts or 10
 * brackets deep, a divisor with no variable that is zero (found by working
 * out each constant divisor), and a formula with no variable.
 *
 * ## Refused on a loan (evaluate())
 *
 * A divisor that works out to zero, and a result below zero, each as a
 * {@see FeeExpressionException} the caller turns into a 422 naming the fee.
 */
final class FeeExpression
{
    public const MAX_LENGTH = 255;

    public const MAX_TOKENS = 64;

    public const MAX_DEPTH = 10;

    /**
     * Each variable's key in the inputs ({@see FeeFormulaInputs::values()})
     * and its name as written.
     *
     * @var array<string, string>
     */
    public const VARIABLES = [
        'principal_amount' => 'Principal Amount',
        'loan_amount' => 'Loan Amount',
        'interest_rate' => 'Interest Rate',
        'loan_term' => 'Loan Term',
        'term_in_days' => 'Term in Days',
    ];

    private const TOO_LONG = 'The formula is too long (at most 255 characters, 64 parts and 10 brackets deep).';

    private const STRAY = 'Use only numbers, the variables, + − × ÷ % and brackets.';

    private const EMPTY = 'Write a formula, for example (Principal Amount × 2.5%) ÷ 365.';

    private const NO_VARIABLE = 'The formula needs at least one variable; for a fixed amount use the Fixed type.';

    private const DIVIDES_BY_ZERO = 'The formula divides by zero.';

    private const OPERATORS = ['+' => '+', '-' => '-', '−' => '-', '*' => '*', '×' => '*', '/' => '/', '÷' => '/'];

    /** @var list<array{type: string, value: string, text: string}> */
    private array $tokens = [];

    private int $position = 0;

    /**
     * @param  array<int|string, mixed>  $tree
     * @param  list<string>  $variableKeys
     */
    private function __construct(private readonly string $source, private readonly array $tree, private readonly array $variableKeys) {}

    /**
     * @throws FeeExpressionException when the formula is refused (INVALID)
     */
    public static function parse(string $source): self
    {
        $source = trim($source);

        if ($source === '') {
            throw FeeExpressionException::invalid(self::EMPTY);
        }

        if (mb_strlen($source) > self::MAX_LENGTH) {
            throw FeeExpressionException::invalid(self::TOO_LONG);
        }

        $parser = new self($source, [], []);
        $parser->tokens = self::tokenize($source);
        $tree = $parser->expression();

        if ($parser->position < count($parser->tokens)) {
            throw FeeExpressionException::invalid($parser->unexpected($parser->tokens[$parser->position]));
        }

        self::refuseConstantZeroDivisors($tree);

        $keys = [];
        self::collectVariables($tree, $keys);

        if ($keys === []) {
            throw FeeExpressionException::invalid(self::NO_VARIABLE);
        }

        return new self($source, $tree, $keys);
    }

    /** The formula as written, trimmed. */
    public function source(): string
    {
        return $this->source;
    }

    /**
     * The variables used, each once, in the order they first appear, by name.
     *
     * @return list<string>
     */
    public function variables(): array
    {
        return array_map(static fn (string $key): string => self::VARIABLES[$key], $this->variableKeys);
    }

    /**
     * The exact result for these inputs, or null when an input the formula
     * uses isn't known yet (a form preview with no dates or rate).
     *
     * @param  array<string, BigRational|null>  $values  keyed as {@see self::VARIABLES}
     *
     * @throws FeeExpressionException DIVIDES_BY_ZERO or NEGATIVE
     */
    public function evaluate(array $values): ?BigRational
    {
        foreach ($this->variableKeys as $key) {
            if (($values[$key] ?? null) === null) {
                return null;
            }
        }

        $result = self::valueOf($this->tree, $values);

        if ($result->isNegative()) {
            throw FeeExpressionException::negative();
        }

        return $result;
    }

    /**
     * @return list<array{type: string, value: string, text: string}>
     */
    private static function tokenize(string $source): array
    {
        $tokens = [];
        $depth = 0;
        $offset = 0;
        $length = strlen($source);

        while ($offset < $length) {
            if (preg_match('/\G\s+/u', $source, $match, 0, $offset) === 1) {
                $offset += strlen($match[0]);

                continue;
            }

            if (preg_match('/\G\d+(?:\.\d+)?/u', $source, $match, 0, $offset) === 1) {
                $tokens[] = ['type' => 'number', 'value' => $match[0], 'text' => $match[0]];
            } elseif (preg_match('/\G[A-Za-z]+(?:\s+[A-Za-z]+)*/u', $source, $match, 0, $offset) === 1) {
                $tokens[] = self::variableToken($match[0]);
            } elseif (preg_match('/\G[+\-−*×\/÷%()]/u', $source, $match, 0, $offset) === 1) {
                $tokens[] = self::symbolToken($match[0], $depth);
            } else {
                throw FeeExpressionException::invalid(self::STRAY);
            }

            $offset += strlen($match[0]);

            if (count($tokens) > self::MAX_TOKENS) {
                throw FeeExpressionException::invalid(self::TOO_LONG);
            }
        }

        if ($depth > 0) {
            throw FeeExpressionException::invalid('A bracket is opened and never closed.');
        }

        return $tokens;
    }

    /**
     * @return array{type: string, value: string, text: string}
     */
    private static function variableToken(string $written): array
    {
        $normalised = strtolower((string) preg_replace('/\s+/', ' ', $written));

        foreach (self::VARIABLES as $key => $name) {
            if (strtolower($name) === $normalised) {
                return ['type' => 'variable', 'value' => $key, 'text' => $name];
            }
        }

        $names = array_values(self::VARIABLES);
        $list = implode(', ', array_slice($names, 0, -1)).' or '.end($names);
        $shown = (string) preg_replace('/\s+/', ' ', $written);

        throw FeeExpressionException::invalid("Use a variable name from the list: {$list}. '{$shown}' isn't one.");
    }

    /**
     * @return array{type: string, value: string, text: string}
     */
    private static function symbolToken(string $symbol, int &$depth): array
    {
        if ($symbol === '(') {
            if (++$depth > self::MAX_DEPTH) {
                throw FeeExpressionException::invalid(self::TOO_LONG);
            }

            return ['type' => 'open', 'value' => '(', 'text' => '('];
        }

        if ($symbol === ')') {
            if (--$depth < 0) {
                throw FeeExpressionException::invalid('A bracket is closed that was never opened.');
            }

            return ['type' => 'close', 'value' => ')', 'text' => ')'];
        }

        if ($symbol === '%') {
            return ['type' => 'percent', 'value' => '%', 'text' => '%'];
        }

        return ['type' => 'operator', 'value' => self::OPERATORS[$symbol], 'text' => $symbol];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function expression(): array
    {
        $left = $this->term();

        while ($this->peekOperator(['+', '-'])) {
            $operator = $this->tokens[$this->position++]['value'];
            $left = ['binary', $operator, $left, $this->term()];
        }

        return $left;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function term(): array
    {
        $left = $this->unary();

        while ($this->peekOperator(['*', '/'])) {
            $operator = $this->tokens[$this->position++]['value'];
            $left = ['binary', $operator, $left, $this->unary()];
        }

        return $left;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function unary(): array
    {
        if ($this->peekOperator(['-'])) {
            $this->position++;

            return ['negate', $this->unary()];
        }

        $node = $this->primary();

        if (($this->tokens[$this->position]['type'] ?? null) === 'percent') {
            $this->position++;
            $node = ['percent', $node];
        }

        return $node;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function primary(): array
    {
        $token = $this->tokens[$this->position] ?? null;

        if ($token === null || in_array($token['type'], ['operator', 'percent', 'close'], true)) {
            throw FeeExpressionException::invalid($this->missingOperand($token));
        }

        $this->position++;

        if ($token['type'] === 'number') {
            return ['number', BigRational::of($token['value'])];
        }

        if ($token['type'] === 'variable') {
            return ['variable', $token['value']];
        }

        $inner = $this->expression();
        $close = $this->tokens[$this->position] ?? null;

        if (($close['type'] ?? null) !== 'close') {
            throw FeeExpressionException::invalid($this->unexpected($close));
        }

        $this->position++;

        return $inner;
    }

    /**
     * @param  list<string>  $operators
     */
    private function peekOperator(array $operators): bool
    {
        $token = $this->tokens[$this->position] ?? null;

        return $token !== null && $token['type'] === 'operator' && in_array($token['value'], $operators, true);
    }

    /**
     * @param  array{type: string, value: string, text: string}|null  $token
     */
    private function missingOperand(?array $token): string
    {
        $previous = $this->tokens[$this->position - 1] ?? null;

        if ($previous === null && $token !== null) {
            return "Something is missing before \"{$token['text']}\": a number, a variable or a bracket.";
        }

        return "Something is missing after \"{$previous['text']}\": a number, a variable or a bracket.";
    }

    /**
     * What to say about a token found where an operator or the end belongs.
     *
     * @param  array{type: string, value: string, text: string}|null  $token
     */
    private function unexpected(?array $token): string
    {
        $previous = $this->tokens[$this->position - 1] ?? null;

        if ($token === null || $previous === null) {
            return self::STRAY;
        }

        if ($token['type'] === 'percent') {
            return 'Use one % sign at a time, after a number, a variable or a bracket.';
        }

        return "Put an operator (+ − × ÷) between \"{$previous['text']}\" and \"{$token['text']}\".";
    }

    /**
     * Refuse a divisor with no variable in it that works out to zero
     * ("÷ 0", "÷ (2 − 2)"), so a formula that can never be charged is never
     * saved.
     *
     * @param  array<int|string, mixed>  $node
     */
    private static function refuseConstantZeroDivisors(array $node): void
    {
        foreach (self::children($node) as $child) {
            self::refuseConstantZeroDivisors($child);
        }

        if ($node[0] !== 'binary' || $node[1] !== '/') {
            return;
        }

        $keys = [];
        self::collectVariables($node[3], $keys);

        if ($keys === [] && self::valueOf($node[3], [])->isZero()) {
            throw FeeExpressionException::invalid(self::DIVIDES_BY_ZERO);
        }
    }

    /**
     * @param  array<int|string, mixed>  $node
     * @param  list<string>  $keys
     */
    private static function collectVariables(array $node, array &$keys): void
    {
        if ($node[0] === 'variable' && ! in_array($node[1], $keys, true)) {
            $keys[] = $node[1];
        }

        foreach (self::children($node) as $child) {
            self::collectVariables($child, $keys);
        }
    }

    /**
     * @param  array<int|string, mixed>  $node
     * @return list<array<int|string, mixed>>
     */
    private static function children(array $node): array
    {
        return match ($node[0]) {
            'binary' => [$node[2], $node[3]],
            'negate', 'percent' => [$node[1]],
            default => [],
        };
    }

    /**
     * @param  array<int|string, mixed>  $node
     * @param  array<string, BigRational|null>  $values
     */
    private static function valueOf(array $node, array $values): BigRational
    {
        return match ($node[0]) {
            'number' => $node[1],
            'variable' => $values[$node[1]],
            'negate' => self::valueOf($node[1], $values)->negated(),
            'percent' => self::valueOf($node[1], $values)->dividedBy(100),
            default => self::binary($node, $values),
        };
    }

    /**
     * @param  array<int|string, mixed>  $node
     * @param  array<string, BigRational|null>  $values
     */
    private static function binary(array $node, array $values): BigRational
    {
        $left = self::valueOf($node[2], $values);
        $right = self::valueOf($node[3], $values);

        if ($node[1] === '/' && $right->isZero()) {
            $detail = $node[3][0] === 'variable' ? self::VARIABLES[$node[3][1]].' is 0' : null;

            throw FeeExpressionException::dividesByZero($detail);
        }

        return match ($node[1]) {
            '+' => $left->plus($right),
            '-' => $left->minus($right),
            '*' => $left->multipliedBy($right),
            default => $left->dividedBy($right),
        };
    }
}
