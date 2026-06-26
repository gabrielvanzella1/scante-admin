<?php
namespace App\Models;

use App\Core\Model;

class Licenca extends Model {
    protected string $table = 'licencas';

    public function gerar(int $empresaId, string $tipo = 'trial', int $dias = 30): int {
        $chave = $this->gerarChave();
        $expira = $tipo === 'vitalicia' ? null : date('Y-m-d H:i:s', strtotime("+{$dias} days"));

        $this->db->execute(
            "INSERT INTO licencas (chave, empresa_id, tipo, status, expira_em) VALUES (?,?,?,?,?)",
            [$chave, $empresaId, $tipo, $tipo === 'trial' ? 'trial' : 'ativa', $expira]
        );
        return (int)$this->db->lastInsertId();
    }

    public function gerarChave(): string {
        do {
            $chave = 'SCTE-'
                . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)) . '-'
                . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)) . '-'
                . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        } while ($this->db->queryOne("SELECT id FROM licencas WHERE chave = ?", [$chave]));
        return $chave;
    }

    public function findByChave(string $chave): ?array {
        return $this->db->queryOne(
            "SELECT l.*, e.nome AS empresa_nome
             FROM licencas l
             LEFT JOIN empresas e ON e.id = l.empresa_id
             WHERE l.chave = ?",
            [$chave]
        );
    }

    public function findByEmpresa(int $empresaId): array {
        return $this->db->query(
            "SELECT * FROM licencas WHERE empresa_id = ? ORDER BY criada_em DESC",
            [$empresaId]
        );
    }

    public function comEmpresa(): array {
        return $this->db->query("
            SELECT l.*, e.nome AS empresa_nome
            FROM licencas l
            LEFT JOIN empresas e ON e.id = l.empresa_id
            ORDER BY l.criada_em DESC
        ");
    }

    public function vincularDispositivo(int $id, string $deviceId, string $deviceNome = ''): void {
        $this->db->execute(
            "UPDATE licencas SET device_id=?, device_nome=?, vinculada_em=NOW() WHERE id=?",
            [$deviceId, $deviceNome, $id]
        );
        // Registra histórico
        $this->db->execute(
            "INSERT INTO historico_dispositivos (licenca_id, device_id, device_nome, acao) VALUES (?,?,?,'vincular')",
            [$id, $deviceId, $deviceNome]
        );
    }

    public function transferir(int $id, string $motivo, int $usuarioId): bool {
        $licenca = $this->findById($id);
        if (!$licenca) return false;

        $deviceAntigo = $licenca['device_id'];

        $this->db->execute(
            "UPDATE licencas SET device_id=NULL, device_nome=NULL, vinculada_em=NULL WHERE id=?",
            [$id]
        );

        $this->db->execute(
            "INSERT INTO historico_dispositivos (licenca_id, device_id, device_nome, acao, motivo, usuario_id)
             VALUES (?,?,?,'transferencia',?,?)",
            [$id, $deviceAntigo, $licenca['device_nome'] ?? '', $motivo, $usuarioId]
        );
        return true;
    }

    public function revogar(int $id): void {
        $this->db->execute(
            "UPDATE licencas SET status='revogada' WHERE id=?", [$id]
        );
    }

    public function reativar(int $id): void {
        $this->db->execute(
            "UPDATE licencas SET status='ativa' WHERE id=?", [$id]
        );
    }

    public function alterarTipo(int $id, string $novoTipo, ?int $dias = null): void {
        if ($novoTipo === 'vitalicia') {
            // Vitalícia nunca expira: zera data e garante status ativo
            $this->db->execute(
                "UPDATE licencas SET tipo='vitalicia', expira_em=NULL, status=IF(status='revogada','revogada','ativa') WHERE id=?",
                [$id]
            );
        } else {
            // Mudando para tipo com prazo: dias é obrigatório se não houver expira_em atual
            $licenca = $this->findById($id);
            $expiraAtual = $licenca['expira_em'] ?? null;

            if ($dias) {
                $expira = date('Y-m-d H:i:s', strtotime("+{$dias} days"));
            } elseif ($expiraAtual) {
                $expira = $expiraAtual; // mantém data existente
            } else {
                // Sem data e sem dias informados: não há como saber quando expira,
                // deixa NULL mas o tipo é atualizado (admin deverá estender depois)
                $expira = null;
            }

            $this->db->execute(
                "UPDATE licencas SET tipo=?, expira_em=?, status=IF(status='revogada','revogada','ativa') WHERE id=?",
                [$novoTipo, $expira, $id]
            );
        }
    }

    public function estender(int $id, int $dias): void {
        // Não estende licença vitalícia
        $licenca = $this->findById($id);
        if (!$licenca || $licenca['tipo'] === 'vitalicia') return;

        $this->db->execute(
            "UPDATE licencas SET
                expira_em = DATE_ADD(IFNULL(expira_em, NOW()), INTERVAL ? DAY),
                status = 'ativa'
             WHERE id=?",
            [$dias, $id]
        );
    }

    public function validarParaApp(string $chave, string $deviceId, string $deviceNome = ''): array {
        $licenca = $this->findByChave($chave);

        if (!$licenca) {
            return ['valida' => false, 'mensagem' => 'Licença não encontrada.'];
        }

        if ($licenca['status'] === 'revogada') {
            return ['valida' => false, 'mensagem' => 'Licença revogada.'];
        }

        // Vitalícia nunca expira, independente do que estiver no banco
        if ($licenca['tipo'] !== 'vitalicia' && $licenca['expira_em'] && strtotime($licenca['expira_em']) < time()) {
            $this->db->execute("UPDATE licencas SET status='expirada' WHERE id=?", [$licenca['id']]);
            return ['valida' => false, 'mensagem' => 'Licença expirada.'];
        }

        // Verifica vínculo de dispositivo
        if ($licenca['device_id'] && $licenca['device_id'] !== $deviceId) {
            return ['valida' => false, 'mensagem' => 'Licença vinculada a outro dispositivo.'];
        }

        // Vincula dispositivo na primeira vez
        if (!$licenca['device_id']) {
            $this->vincularDispositivo($licenca['id'], $deviceId, $deviceNome);
        }

        // Atualiza último acesso
        $this->db->execute(
            "UPDATE licencas SET ultimo_acesso=NOW() WHERE id=?", [$licenca['id']]
        );

        $diasRestantes = $licenca['expira_em']
            ? max(0, (int)ceil((strtotime($licenca['expira_em']) - time()) / 86400))
            : null;

        return [
            'valida'         => true,
            'tipo'           => $licenca['tipo'],
            'status'         => $licenca['status'],
            'dias_restantes' => $diasRestantes,
            'vitalicia'      => $licenca['tipo'] === 'vitalicia',
            'mensagem'       => 'Licença válida.',
        ];
    }

    public function estatisticas(): array {
        return $this->db->queryOne("
            SELECT
                COUNT(*)                                                                                                                               AS total,
                SUM(status IN ('ativa','trial'))                                                                                                       AS ativas,
                SUM(tipo   = 'trial')                                                                                                                  AS trial,
                SUM(tipo   = 'mensal')                                                                                                                 AS mensal,
                SUM(tipo   = 'anual')                                                                                                                  AS anual,
                SUM(tipo   = 'vitalicia')                                                                                                              AS vitalicias,
                SUM(status = 'expirada')                                                                                                               AS expiradas,
                SUM(status = 'revogada')                                                                                                               AS revogadas,
                SUM(device_id IS NOT NULL AND status IN ('ativa','trial'))                                                                             AS dispositivos_ativos,
                SUM(tipo != 'vitalicia' AND expira_em IS NOT NULL AND expira_em BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY) AND status='ativa') AS expirando_7d
            FROM licencas
        ") ?? [];
    }

    public function historico(int $licencaId): array {
        return $this->db->query(
            "SELECT h.*, u.nome AS usuario_nome
             FROM historico_dispositivos h
             LEFT JOIN usuarios u ON u.id = h.usuario_id
             WHERE h.licenca_id = ?
             ORDER BY h.criado_em DESC",
            [$licencaId]
        );
    }
}
