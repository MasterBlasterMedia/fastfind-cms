<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateOwner extends Command
{
    protected $signature = 'fastfind:owner {--email= : Email for the owner account}';
    protected $description = 'Create a first CMS owner securely (interactive password prompt)';

    public function handle(): int
    {
        $name = $this->ask('Your name', 'Site Owner');
        $email = $this->option('email') ?: $this->ask('Email address');
        $password = $this->secret('Password (12+ characters)');
        $passwordAgain = $this->secret('Confirm password');
        if ($password !== $passwordAgain) { $this->error('Passwords do not match.'); return self::FAILURE; }
        $validator = Validator::make(compact('name', 'email', 'password'), [
            'name' => 'required|string|max:120', 'email' => 'required|email|max:254|unique:users,email',
            'password' => 'required|string|min:12',
        ]);
        if ($validator->fails()) { $this->error($validator->errors()->first()); return self::FAILURE; }
        User::create(['name' => $name, 'email' => $email, 'password' => $password, 'role' => 'owner', 'is_active' => true]);
        $this->info('Owner created. Log in at /studio.');
        return self::SUCCESS;
    }
}
