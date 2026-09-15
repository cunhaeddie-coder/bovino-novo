<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use LogicException;

// SCHEMA-CONTRATO-AUTENTICACAO.md §1/§2 — Usuario vira o próprio
// Authenticatable do Sanctum (Vertical 33), sem model de credenciais
// separado: os 32 verticais anteriores já constroem toda autorização
// (INV-029, garantirRelacaoComFazenda) em cima deste model, introduzir um
// segundo model só pra credenciais duplicaria identidade sem necessidade.
class Usuario extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = ['nome', 'email', 'celular', 'senha', 'eh_administrador'];

    protected $hidden = ['senha'];

    protected $casts = [
        'eh_administrador' => 'boolean',
    ];

    // Sanctum/Auth chamam getAuthPassword() pra comparar hash — não existe
    // coluna "password" aqui, o campo real é "senha" (VERTICAL-AUTENTICACAO.md
    // §2, vocabulário em português mantido do domínio, não traduzido pro
    // convencional do framework).
    public function getAuthPassword(): ?string
    {
        return $this->senha;
    }

    protected static function booted(): void
    {
        // SCHEMA-CONTRATO-AUTENTICACAO.md §2 — guard de aplicação: um
        // Usuario com senha preenchida (pretende logar) precisa de pelo
        // menos um entre email/celular. Usuario de domínio puro (sem
        // login, criado direto em teste) não precisa de nenhum dos dois.
        $regra = function (self $usuario) {
            if ($usuario->senha !== null && $usuario->email === null && $usuario->celular === null) {
                throw new LogicException('Usuario com senha exige pelo menos um entre email e celular.');
            }
        };
        static::creating($regra);
        static::updating($regra);
    }

    public function papeis(): HasMany
    {
        return $this->hasMany(Papel::class);
    }

    /** INV-029 — a única pergunta que decide qualquer acesso (Spike 005/006). */
    public function temRelacaoComFazenda(int $fazendaId): bool
    {
        return $this->papeis()->where('fazenda_id', $fazendaId)->exists();
    }

    // SCHEMA-CONTRATO-CARTEIRA.md §6 — Titular não tem Papel próprio; a
    // relação sempre passa por pelo menos uma Fazenda daquele Titular.
    public function temRelacaoComTitular(int $titularId): bool
    {
        return Fazenda::where('titular_id', $titularId)
            ->whereIn('id', $this->papeis()->pluck('fazenda_id'))
            ->exists();
    }
}
