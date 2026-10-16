<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use XLaravel\FederatedIdentity\Support\FederatedIdentityConfig;

return new class extends Migration
{
    public function up(): void
    {
        $users = (new (FederatedIdentityConfig::userModel()))->getTable();

        Schema::connection($this->getConnection())->create(FederatedIdentityConfig::table(), function (Blueprint $table) use ($users) {
            $table->id();
            $table->foreignId('user_id')->constrained($users)->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_id');
            $table->string('email')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_id']);
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists(FederatedIdentityConfig::table());
    }

    public function getConnection(): ?string
    {
        return FederatedIdentityConfig::connection();
    }
};
