<?php

namespace App\Services;

use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;

/**
 * O núcleo do Vertical 33 (Autenticação — Gestor/Produtor), nascido de
 * VERTICAL-AUTENTICACAO.md, traduzindo SCHEMA-CONTRATO-AUTENTICACAO.md pra
 * código real. Usuario é o próprio Authenticatable (sem model de
 * credenciais separado) — este Service só decide como uma requisição HTTP
 * chega a saber qual Usuario está falando; a autorização por Fazenda
 * (INV-029, garantirRelacaoComFazenda) continua inteiramente nos 32
 * Services de domínio, sem nenhuma mudança.
 */
class AuthService
{
    public function registrar(string $nome, ?string $email, ?string $celular, string $senha): Usuario
    {
        if (trim($nome) === '') {
            throw new DomainException('Usuario exige nome.');
        }

        if (($email === null || trim($email) === '') && ($celular === null || trim($celular) === '')) {
            throw new DomainException('Cadastro exige pelo menos um entre email e celular.');
        }

        if (trim($senha) === '') {
            throw new DomainException('Cadastro exige senha.');
        }

        try {
            return Usuario::create([
                'nome' => $nome,
                'email' => $email,
                'celular' => $celular,
                'senha' => Hash::make($senha),
                'eh_administrador' => false,
            ]);
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                throw new DomainException('E-mail ou celular já cadastrado.');
            }
            throw $e;
        }
    }

    public function login(string $identificador, string $senha): array
    {
        $identificador = trim($identificador);
        $usuario = str_contains($identificador, '@')
            ? Usuario::where('email', $identificador)->first()
            : Usuario::where('celular', $identificador)->first();

        // Mensagem genérica de propósito — nunca revela se o identificador
        // existe (SCHEMA-CONTRATO-AUTENTICACAO.md §4).
        if (! $usuario || $usuario->senha === null || ! Hash::check($senha, $usuario->senha)) {
            throw new DomainException('Credenciais inválidas.');
        }

        return [
            'usuario' => $usuario,
            'token' => $usuario->createToken('sanctum')->plainTextToken,
        ];
    }

    public function logout(Usuario $usuario): void
    {
        $usuario->currentAccessToken()?->delete();
    }

    private function violacaoDeUnicidade(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
