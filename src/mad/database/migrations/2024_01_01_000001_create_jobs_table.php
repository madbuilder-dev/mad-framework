<?php

use Mad\Service\Migration\MadBaseMigration;
use Illuminate\Database\Schema\Blueprint;

class CreateJobsTable extends MadBaseMigration
{
    public $connection = 'iam';

    public function up(): void
    {
        if (!$this->schema()->hasTable('jobs')) {
            $this->schema()->create('jobs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('jobs');
    }
}
