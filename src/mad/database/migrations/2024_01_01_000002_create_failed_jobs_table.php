<?php

use Mad\Service\Migration\MadBaseMigration;
use Illuminate\Database\Schema\Blueprint;

class CreateFailedJobsTable extends MadBaseMigration
{
    public $connection = 'iam';

    public function up(): void
    {
        if (!$this->schema()->hasTable('failed_jobs')) {
            $this->schema()->create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('failed_jobs');
    }
}
