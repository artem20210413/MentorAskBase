<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class IssueApiTokenCommand extends Command
{
    protected $signature = 'rag:issue-token {name=api-client : Ідентифікатор технічного клієнта}';

    protected $description = 'Видати технічний Sanctum-токен доступу до RAG API (FR-013)';

    public function handle(): int
    {
        $name = $this->argument('name');

        $user = User::firstOrCreate(
            ['email' => "{$name}@rag.local"],
            ['name' => $name, 'password' => bcrypt(str()->random(32))]
        );

        $token = $user->createToken($name);

        $this->info('Технічний токен видано:');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
