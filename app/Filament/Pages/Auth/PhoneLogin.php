<?php

namespace App\Filament\Pages\Auth;

use App\Services\Auth\OneTimeCodeService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\SimplePage;
use Illuminate\Validation\ValidationException;

/**
 * contracts/web-auth.md: 2-кроковий вхід на Filament-панель — телефон → код.
 */
class PhoneLogin extends SimplePage implements HasForms
{
    use InteractsWithFormActions;
    use InteractsWithForms;

    protected static string $view = 'filament.pages.auth.phone-login';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public string $step = 'phone';

    public function mount(): void
    {
        if (Filament::auth()->check()) {
            redirect()->intended(Filament::getUrl());
        }

        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('phone')
                    ->label('Номер телефону')
                    ->tel()
                    ->required()
                    ->autofocus()
                    ->disabled(fn () => $this->step === 'code')
                    // disabled() вимикає дегідрацію за замовчуванням — без
                    // цього $data['phone'] зникає з getState() на кроці "код".
                    ->dehydrated(),
                TextInput::make('code')
                    ->label('Код з Telegram')
                    ->required()
                    ->visible(fn () => $this->step === 'code'),
            ])
            ->statePath('data');
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('submit')
                ->label($this->step === 'phone' ? 'Отримати код' : 'Увійти')
                ->submit('submit'),
        ];
    }

    public function submit(OneTimeCodeService $service): void
    {
        $data = $this->form->getState();

        if ($this->step === 'phone') {
            $result = $service->requestCode($data['phone']);

            if ($result['outcome'] === 'rate_limited') {
                throw ValidationException::withMessages([
                    'data.phone' => 'Забагато спроб. Спробуйте за хвилину.',
                ]);
            }

            if ($result['outcome'] === 'denied') {
                throw ValidationException::withMessages([
                    'data.phone' => 'Немає доступу.',
                ]);
            }

            $this->step = 'code';

            return;
        }

        $result = $service->verifyCode($data['phone'], $data['code']);

        if ($result['outcome'] !== 'success') {
            throw ValidationException::withMessages([
                'data.code' => 'Невірний або застарілий код.',
            ]);
        }

        Filament::auth()->login($result['user']);
        session(['logged_in_at' => now()]);
        session()->regenerate();

        redirect()->intended(Filament::getUrl());
    }

    public function getTitle(): string
    {
        return 'Вхід';
    }

    public function getHeading(): string
    {
        return 'Вхід за номером телефону';
    }

    protected function hasFullWidthFormActions(): bool
    {
        return true;
    }
}
