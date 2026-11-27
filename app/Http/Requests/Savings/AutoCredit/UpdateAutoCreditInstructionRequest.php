<?php

namespace App\Http\Requests\Savings\AutoCredit;

use App\Models\SavingsAutoCreditInstruction;
use App\Services\Savings\AutoCredit\Instructions;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * `PUT` and `DELETE /savings/auto-credit/instructions/{instruction}`
 * (savings:post): permission first (403), then the instruction on an account
 * in the caller's branches (404), then the body. The account never changes.
 */
class UpdateAutoCreditInstructionRequest extends FormRequest
{
    use AutoCreditInstructionRules;

    private ?SavingsAutoCreditInstruction $instruction = null;

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->can('savings:post')) {
            return false;
        }

        $this->instruction = app(Instructions::class)->find($user, (int) $this->route('instruction'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->isMethod('DELETE')) {
            return [];
        }

        return [
            'account_id' => ['prohibited'],
            ...$this->instructionRules(false),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...$this->instructionMessages(),
            'account_id.prohibited' => 'An instruction stays on its account. Delete it and make a new one for another account.',
        ];
    }

    public function instruction(): SavingsAutoCreditInstruction
    {
        return $this->instruction ?? throw new LogicException('This request has no instruction.');
    }
}
