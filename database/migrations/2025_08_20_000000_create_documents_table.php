<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->bigIncrements('pk_document_id');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_type')->nullable();
            $table->string('document_type')->nullable(); // public/private
            $table->timestamp('reg_date')->nullable();
            $table->string('document_no')->nullable();
            $table->string('name');
            $table->string('version_no')->nullable();
            $table->integer('size_kb')->nullable();
            $table->string('ext')->nullable();
            $table->string('original_name')->nullable();
            $table->string('path')->nullable();
            $table->boolean('has_expired')->default(false);
            $table->timestamp('expired_date')->nullable();
            $table->string('storage')->default('s3'); // s3/local
            $table->string('s3_key')->nullable();
            $table->string('s3_bucket')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('created_date')->useCurrent();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('documents');
    }
};
