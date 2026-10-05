<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'admin:create {email} {--name=Administrador} {--password=}';

    protected $description = 'Cria (ou atualiza a senha de) um usuário administrador.';

    public function handle(): int
    {
        $password = $this->option('password') ?: $this->secret('Senha (mín. 8 caracteres)');

        $validator = Validator::make(
            ['email' => $this->argument('email'), 'password' => $password],
            ['email' => ['required', 'email'], 'password' => ['required', 'string', 'min:8']]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $this->argument('email')],
            ['name' => $this->option('name'), 'password' => $password, 'role' => User::ROLE_ADMIN]
        );

        $this->info(($user->wasRecentlyCreated ? 'Admin criado: ' : 'Admin atualizado: ').$user->email);

        return self::SUCCESS;
    }
}
