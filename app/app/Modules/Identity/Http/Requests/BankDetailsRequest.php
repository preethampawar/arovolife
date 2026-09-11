<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the distributor's own bank-details submission (QA F70).
 *
 * The account number is typed twice and both copies must match — a single
 * mistyped digit sends a NEFT transfer to a stranger, and the bank will not
 * tell us it was the wrong person. Rules mirror the registration wizard's bank
 * step so a number accepted at signup is accepted here.
 */
final class BankDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->distributor !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'account_number' => trim((string) $this->input('account_number', '')),
            'account_number_confirmation' => trim((string) $this->input('account_number_confirmation', '')),
            'ifsc' => strtoupper(trim((string) $this->input('ifsc', ''))),
            'bank_name' => trim((string) $this->input('bank_name', '')) ?: null,
            'beneficiary_name' => trim((string) $this->input('beneficiary_name', '')),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'account_number' => ['required', 'string', 'min:9', 'max:18', 'regex:/^\d+$/', 'confirmed'],
            'ifsc' => ['required', 'string', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'beneficiary_name' => ['required', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_number.required' => 'Please enter your bank account number.',
            'account_number.min' => 'Bank account number must be at least 9 digits.',
            'account_number.max' => 'Bank account number must be at most 18 digits.',
            'account_number.regex' => 'Bank account number must contain digits only — no spaces or letters.',
            'account_number.confirmed' => 'The two account numbers do not match. Please type the same number in both boxes.',
            'ifsc.required' => 'Please enter your bank\'s IFSC code.',
            'ifsc.regex' => 'IFSC must be 11 characters: 4 letters, 0, then 6 alphanumeric (e.g. HDFC0001234).',
            'beneficiary_name.required' => 'Please enter the account holder\'s name exactly as your bank has it.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'account_number' => 'bank account number',
            'ifsc' => 'IFSC code',
            'bank_name' => 'bank name',
            'beneficiary_name' => 'account holder name',
        ];
    }
}
