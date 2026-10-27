<?php

namespace App\Console\Commands;

use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// Ferramenta de operação da homologação (Inversão, 26/10/2026), não o
// Onboarding: POST /api/auth/registrar só cria o Usuario, e nenhum vertical
// cria Fazenda (VERTICAL-FAZENDA-PERFIL-PUBLICO.md §7 deixa isso pra um
// vertical futuro de Onboarding/Multi-fazenda). Sem Fazenda e Papel, o
// usuário entra no sistema mas toda tela depende de INV-029 e fica vazia.
#[Signature('fazenda:criar
    {identificador : E-mail ou celular de um usuário já cadastrado}
    {nome : Nome da Fazenda}
    {--estado= : UF da Fazenda, 2 letras (opcional)}')]
#[Description('Cria uma Fazenda e liga o usuário a ela como dono (operação da homologação)')]
class CriarFazenda extends Command
{
    public function handle(): int
    {
        $identificador = trim($this->argument('identificador'));
        $nome = trim($this->argument('nome'));
        $estado = $this->option('estado') !== null ? strtoupper(trim($this->option('estado'))) : null;

        if ($nome === '') {
            $this->error('A Fazenda precisa de nome.');

            return self::FAILURE;
        }

        if ($estado !== null && preg_match('/^[A-Z]{2}$/', $estado) !== 1) {
            $this->error('Estado precisa ser a UF com 2 letras (ex.: MT).');

            return self::FAILURE;
        }

        // Mesma regra do login (AuthService): com @ é e-mail, sem @ é celular.
        $usuario = str_contains($identificador, '@')
            ? Usuario::where('email', $identificador)->first()
            : Usuario::where('celular', $identificador)->first();

        if (! $usuario) {
            $this->error("Nenhum usuário com o identificador {$identificador}. Cadastre primeiro em POST /api/auth/registrar.");

            return self::FAILURE;
        }

        $fazenda = DB::transaction(function () use ($usuario, $nome, $estado) {
            $fazenda = Fazenda::create(['nome' => $nome, 'estado' => $estado]);
            Papel::create(['usuario_id' => $usuario->id, 'fazenda_id' => $fazenda->id, 'papel' => 'dono']);

            return $fazenda;
        });

        $this->info("Fazenda #{$fazenda->id} \"{$fazenda->nome}\" criada; {$usuario->nome} é dono.");

        $outras = $usuario->papeis()->where('fazenda_id', '!=', $fazenda->id)->count();
        if ($outras > 0) {
            $this->warn("{$usuario->nome} já tinha {$outras} outra(s) Fazenda(s). No login, o frontend abre a primeira.");
        }

        return self::SUCCESS;
    }
}
