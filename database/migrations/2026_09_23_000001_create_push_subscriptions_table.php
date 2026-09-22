<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los navegadores a los que hay que tocar cuando pasa algo.
 *
 * Una fila por navegador, no por persona: quien juega en el portatil y mira
 * el movil tiene dos, y las dos tienen que sonar. La direccion que da el
 * navegador al suscribirse es la identidad, por eso es la clave unica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Las de Google rondan los 200 caracteres y las de Mozilla algo
            // menos, pero no hay maximo escrito en ninguna parte. 500 deja
            // sitio de sobra y cabe en el indice unico de MySQL, que con
            // utf8mb4 admite hasta 768 caracteres.
            $table->string('endpoint', 500)->unique();

            // No hacen falta para el aviso vacio que mandamos, pero se
            // guardan: son lo que haria falta el dia que se quiera mandar el
            // texto dentro del push, y pedirselas otra vez al navegador
            // significaria volver a suscribir a todo el mundo.
            $table->string('p256dh', 120)->nullable();
            $table->string('auth', 60)->nullable();

            // Para saber, mirando la tabla, si alguien dejo de recibir desde
            // hace semanas y por que.
            $table->unsignedTinyInteger('fallos')->default(0);
            $table->timestamp('ultimo_ok_at')->nullable();

            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
