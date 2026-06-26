<?php

namespace App\Modules\ForgeWire\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class Validate
{
    /**
     * @param array|string $rules An array of rule strings (preferred) or a pipe-separated string (e.g., ['required', 'min:5', 'email'] or "required|min:5|email").
     * @param array|string $messages Optional custom messages. Can be an array (preferred) or JSON string (backward compatible).
     *                                Array example: ['required' => 'Name is required', 'min' => 'Name must be at least :value characters']
     *                                JSON example: '{"required": "Name is required", "min": "Name must be at least :value characters"}'
     */
    public function __construct(
        public array|string $rules,
        public array|string $messages = []
    ) {
    }
}