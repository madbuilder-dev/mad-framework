<?php

use Mad\Service\Migration\MadBaseMigration;
use Illuminate\Database\Schema\Blueprint;

class CreateNotificationsTable extends MadBaseMigration
{
    public $connection = 'iam';

    public function up(): void
    {
        if (!$this->schema()->hasTable('notifications')) {
            $this->schema()->create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->string('notifiable_type');
                $table->unsignedBigInteger('notifiable_id');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->index(['notifiable_type', 'notifiable_id']);
            });
        }
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('notifications');
    }
}
