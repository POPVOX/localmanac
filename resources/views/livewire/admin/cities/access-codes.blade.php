<div class="admin-page">
    <div class="admin-page-header">
        <div>
            <div class="admin-kicker">{{ __('Member access') }}</div>
            <flux:heading size="xl" level="1" class="mt-2 font-serif !text-4xl !font-medium tracking-[-0.03em] text-[#123e32]">
                {{ __('Access codes · :city', ['city' => $city->name]) }}
            </flux:heading>
            <flux:subheading class="mt-2 max-w-2xl">
                {{ __('Create a separate code for each partner, organizer, or campaign and see which one granted each membership.') }}
            </flux:subheading>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button variant="ghost" :href="route('admin.cities.edit', $city)" wire:navigate>{{ __('Location settings') }}</flux:button>
            <flux:button variant="ghost" :href="route('admin.cities.index')" wire:navigate>{{ __('Back to locations') }}</flux:button>
        </div>
    </div>

    @if ($createdCodePlainText)
        <flux:callout variant="success" icon="check-circle" :heading="__('Copy this code now')">
            <div class="space-y-3">
                <p>{{ __('The plain-text code for :label will not be shown again after you leave this page.', ['label' => $createdCodeLabel]) }}</p>
                <code class="block select-all rounded-lg bg-white px-4 py-3 text-base font-semibold tracking-wide text-[#18342c] ring-1 ring-[#d9d7ce]">{{ $createdCodePlainText }}</code>
                <flux:button size="sm" variant="subtle" wire:click="clearCreatedCode">{{ __('I saved it') }}</flux:button>
            </div>
        </flux:callout>
    @endif

    @php
        $attributedMemberCount = $codes->sum('redemptions_count');
    @endphp
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="admin-stat !py-4">
            <div class="admin-kicker">{{ __('Total codes') }}</div>
            <div class="mt-2 text-3xl font-semibold tracking-[-0.04em] text-[#18342c]">{{ $codes->count() }}</div>
        </div>
        <div class="admin-stat !py-4">
            <div class="admin-kicker">{{ __('Available now') }}</div>
            <div class="mt-2 text-3xl font-semibold tracking-[-0.04em] text-[#18342c]">{{ $codes->filter->isAvailable()->count() }}</div>
        </div>
        <div class="admin-stat !py-4">
            <div class="admin-kicker">{{ __('Attributed members') }}</div>
            <div class="mt-2 text-3xl font-semibold tracking-[-0.04em] text-[#18342c]">{{ $attributedMemberCount }}</div>
        </div>
        <div class="admin-stat !py-4">
            <div class="admin-kicker">{{ __('Unattributed members') }}</div>
            <div class="mt-2 text-3xl font-semibold tracking-[-0.04em] text-[#18342c]">{{ max(0, $memberCount - $attributedMemberCount) }}</div>
            <div class="mt-1 text-xs text-[#718078]">{{ __('Granted before tracking or added by an admin') }}</div>
        </div>
    </div>

    <flux:card padding="xl" class="admin-panel space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Create an access code') }}</flux:heading>
            <flux:text variant="subtle" class="mt-1">{{ __('Use a descriptive label so reports remain useful after the campaign ends.') }}</flux:text>
        </div>

        <form wire:submit.prevent="createCode" class="space-y-5">
            <div class="grid gap-4 md:grid-cols-2">
                <flux:input
                    wire:model="label"
                    :label="__('Campaign or partner label')"
                    type="text"
                    required
                    placeholder="{{ __('Example: Library fall newsletter') }}"
                />

                <flux:input
                    wire:model="plainTextCode"
                    :label="__('Access code')"
                    type="text"
                    required
                    minlength="8"
                    autocomplete="off"
                    placeholder="{{ __('At least 8 characters') }}"
                />
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <flux:input
                    wire:model="description"
                    :label="__('Internal notes (optional)')"
                    type="text"
                    placeholder="{{ __('Who is sharing it and where') }}"
                />

                <flux:input
                    wire:model="expiresAt"
                    :label="__('Expires at (optional)')"
                    type="datetime-local"
                    helper="{{ __('Time is interpreted in :timezone.', ['timezone' => $timezone]) }}"
                />
            </div>

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" icon="plus" wire:loading.attr="disabled" wire:target="createCode">
                    <span wire:loading.remove wire:target="createCode">{{ __('Create code') }}</span>
                    <span wire:loading wire:target="createCode">{{ __('Creating…') }}</span>
                </flux:button>
            </div>
        </form>
    </flux:card>

    <flux:card padding="lg" class="admin-panel overflow-hidden">
        <div class="mb-5">
            <flux:heading size="lg">{{ __('Codes') }}</flux:heading>
            <flux:text variant="subtle" class="mt-1">{{ __('Codes are stored securely and cannot be displayed after creation.') }}</flux:text>
        </div>

        <flux:table>
            <flux:table.columns sticky>
                <flux:table.column sticky>{{ __('Campaign or partner') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Members') }}</flux:table.column>
                <flux:table.column>{{ __('Last used') }}</flux:table.column>
                <flux:table.column>{{ __('Expires') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($codes as $code)
                    @php
                        $expired = $code->expires_at?->isPast() === true;
                        $statusLabel = $expired ? __('Expired') : ($code->is_active ? __('Active') : __('Paused'));
                        $statusColor = $expired ? 'amber' : ($code->is_active ? 'green' : 'zinc');
                    @endphp
                    <flux:table.row :key="$code->id">
                        <flux:table.cell variant="strong" sticky>
                            <div class="flex flex-col gap-1">
                                <span>{{ $code->label }}</span>
                                @if ($code->description)<flux:text size="sm" variant="subtle">{{ $code->description }}</flux:text>@endif
                                @if ($code->is_legacy)<flux:text size="sm" variant="subtle">{{ __('Migrated from the original city code') }}</flux:text>@endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell><flux:badge :color="$statusColor" variant="subtle">{{ $statusLabel }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ $code->redemptions_count }}</flux:table.cell>
                        <flux:table.cell>{{ $code->last_redeemed_at?->tz($timezone)->diffForHumans() ?? __('Never') }}</flux:table.cell>
                        <flux:table.cell>{{ $code->expires_at?->tz($timezone)->format('M j, Y g:i A') ?? __('No expiration') }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button
                                size="sm"
                                variant="ghost"
                                wire:click="toggleCode({{ $code->id }})"
                                wire:loading.attr="disabled"
                                wire:target="toggleCode({{ $code->id }})"
                            >
                                {{ $code->is_active ? __('Pause') : __('Activate') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6"><flux:text variant="subtle">{{ __('No access codes have been created for this city.') }}</flux:text></flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:card padding="lg" class="admin-panel overflow-hidden">
        <div class="mb-5">
            <flux:heading size="lg">{{ __('Recent access grants') }}</flux:heading>
            <flux:text variant="subtle" class="mt-1">{{ __('Each member is attributed to the first code that unlocked this city.') }}</flux:text>
        </div>

        <flux:table :paginate="$redemptions">
            <flux:table.columns sticky>
                <flux:table.column sticky>{{ __('Member') }}</flux:table.column>
                <flux:table.column>{{ __('Campaign or partner') }}</flux:table.column>
                <flux:table.column>{{ __('Granted') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($redemptions as $redemption)
                    <flux:table.row :key="$redemption->id">
                        <flux:table.cell variant="strong" sticky>
                            <div class="flex flex-col gap-1">
                                <span>{{ $redemption->user?->name ?? __('Deleted user') }}</span>
                                @if ($redemption->user)<flux:text size="sm" variant="subtle">{{ $redemption->user->email }}</flux:text>@endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>{{ $redemption->accessCode?->label ?? __('Deleted code') }}</flux:table.cell>
                        <flux:table.cell title="{{ $redemption->redeemed_at?->tz($timezone)->toDateTimeString() }}">
                            {{ $redemption->redeemed_at?->tz($timezone)->diffForHumans() ?? '—' }}
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="3"><flux:text variant="subtle">{{ __('No members have redeemed a tracked code yet.') }}</flux:text></flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
