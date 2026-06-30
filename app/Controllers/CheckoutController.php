<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\Licenca;
use App\Models\Empresa;
use App\Models\Configuracao;
use App\Services\PagarmeService;
use App\Services\MercadoPagoService;

class CheckoutController extends Controller {

    private array $precos = [
        'mensal'    => PRECO_MENSAL,
        'anual'     => PRECO_ANUAL,
        'vitalicia' => PRECO_VITALICIA,
    ];

    private array $labels = [
        'mensal'    => 'Mensal',
        'anual'     => 'Anual',
        'vitalicia' => 'Vitalícia',
    ];

    // ----------------------------------------------------------------
    // Formulário de dados
    // ----------------------------------------------------------------

    public function index(): void {
        $deviceId   = trim($_GET['device_id']   ?? '');
        $deviceNome = trim($_GET['device_nome'] ?? '');
        $empresaId  = (int)($_GET['empresa_id'] ?? 0) ?: null;
        $email      = trim($_GET['email'] ?? '');

        $this->view('checkout.index', [
            'deviceId'        => $deviceId,
            'deviceNome'      => $deviceNome,
            'empresaId'       => $empresaId,
            'empresas'        => (new Empresa())->findAll('nome ASC'),
            'dados'           => ['email' => $email],
            'erro'            => null,
            'novaEmpresaNome' => null,
        ], 'checkout');
    }

    // ----------------------------------------------------------------
    // Processa formulário → cria licença pendente → redireciona
    // ----------------------------------------------------------------

    public function processar(): void {
        $deviceId   = trim($this->input('device_id', ''));
        $deviceNome = trim($this->input('device_nome', ''));
        $email      = trim($this->input('email', ''));
        $telefone   = trim($this->input('telefone', ''));
        $tipo       = $this->input('tipo', 'mensal');
        $empresaId  = (int)$this->input('empresa_id') ?: null;

        $novaEmpresaNome     = trim($this->input('nova_empresa_nome', ''));
        $novaEmpresaCnpj     = trim($this->input('nova_empresa_cnpj', ''));
        $novaEmpresaTelefone = trim($this->input('nova_empresa_telefone', ''));
        $novaEmpresaContato  = trim($this->input('nova_empresa_contato', ''));

        $empresas = (new Empresa())->findAll('nome ASC');

        if (!$email) {
            $this->view('checkout.index', [
                'deviceId' => $deviceId, 'deviceNome' => $deviceNome,
                'empresaId' => $empresaId, 'empresas' => $empresas,
                'dados' => compact('email', 'telefone', 'tipo'),
                'erro' => 'O e-mail é obrigatório.', 'novaEmpresaNome' => null,
            ], 'checkout');
            return;
        }

        if (!isset($this->precos[$tipo])) {
            $tipo = 'mensal';
        }

        if ($novaEmpresaNome && !$empresaId) {
            $empresaId = (new Empresa())->create([
                'nome'     => $novaEmpresaNome,
                'cnpj'     => $novaEmpresaCnpj ?: null,
                'email'    => $email,
                'telefone' => $novaEmpresaTelefone ?: $telefone ?: null,
                'contato'  => $novaEmpresaContato ?: null,
            ]);
        }

        $licencaId = (new Licenca())->criarPendente(
            $empresaId, $tipo, $deviceId, $deviceNome, $email, $telefone
        );

        $this->redirectTo(APP_URL . '/checkout/pagamento?id=' . $licencaId . '&h=' . $this->token($licencaId));
    }

    // ----------------------------------------------------------------
    // Página de pagamento
    // ----------------------------------------------------------------

    public function pagamento(): void {
        $licencaId = (int)($_GET['id'] ?? 0);
        $token     = $_GET['h'] ?? '';

        if (!$licencaId || !hash_equals($this->token($licencaId), $token)) {
            $this->redirectTo(APP_URL . '/checkout');
            return;
        }

        $licenca = (new Licenca())->findById($licencaId);
        if (!$licenca || $licenca['status'] !== 'pendente') {
            $this->redirectTo(APP_URL . '/checkout/sucesso');
            return;
        }

        $tipo    = $licenca['tipo'];
        $valor   = $this->precos[$tipo]  ?? PRECO_MENSAL;
        $label   = $this->labels[$tipo]  ?? ucfirst($tipo);
        $gateway = Configuracao::gatewayAtivo();
        $cfg     = new Configuracao();

        $pixData = null;
        $erroGw  = isset($_GET['erro']);

        if ($gateway === 'pagarme' && !$erroGw) {
            $sk = $cfg->get('pagarme_secret_key');
            if ($sk) {
                try {
                    $svc     = new PagarmeService($sk);
                    $pixData = $svc->criarOrdemPix(
                        $licencaId, $valor,
                        'ScanTE — Licença ' . $label,
                        $licenca['email'] ?? 'cliente@scante.com',
                        $licenca['device_nome'] ?: 'Cliente ScanTE',
                        3600
                    );
                } catch (\Throwable $e) {
                    $erroGw = true;
                    error_log('[Pagar.me] ' . $e->getMessage());
                }
            }
        }

        $this->view('checkout.pagamento', [
            'licenca'     => $licenca,
            'licencaId'   => $licencaId,
            'token'       => $token,
            'tipo'        => $tipo,
            'label'       => $label,
            'valor'       => $valor,
            'dias'        => ['mensal' => 30, 'anual' => 365, 'vitalicia' => null][$tipo] ?? null,
            'gateway'     => $gateway,
            'pixData'     => $pixData,
            'erroGw'      => $erroGw,
            'mpPublicKey' => $cfg->get('mp_public_key'),
        ], 'checkout');
    }

    // ----------------------------------------------------------------
    // POST /checkout/pagar — dev e mercadopago
    // ----------------------------------------------------------------

    public function pagar(): void {
        $licencaId = (int)$this->input('licenca_id');
        $token     = $this->input('token', '');

        if (!$licencaId || !hash_equals($this->token($licencaId), $token)) {
            $this->redirectTo(APP_URL . '/checkout');
            return;
        }

        $licenca = (new Licenca())->findById($licencaId);
        if (!$licenca || $licenca['status'] !== 'pendente') {
            $this->redirectTo(APP_URL . '/checkout/sucesso');
            return;
        }

        $tipo    = $licenca['tipo'];
        $valor   = $this->precos[$tipo] ?? PRECO_MENSAL;
        $gateway = Configuracao::gatewayAtivo();
        $cfg     = new Configuracao();

        if ($gateway === 'mercadopago') {
            $accessToken = $cfg->get('mp_access_token');
            if (!$accessToken) {
                $this->redirectTo(APP_URL . '/checkout/pagamento?id=' . $licencaId . '&h=' . $token . '&erro=1');
                return;
            }
            try {
                $url = (new MercadoPagoService($accessToken))->criarPreferencia(
                    $licencaId, $tipo, $valor,
                    $licenca['email'] ?? 'cliente@scante.com',
                    $licenca['device_nome'] ?? 'ScanTE',
                    APP_URL
                );
                // Guarda na sessão para recuperar a chave na página de sucesso
                $_SESSION['checkout_licenca_id'] = $licencaId;
                $_SESSION['checkout_token']      = $this->token($licencaId);
                $this->redirectTo($url);
            } catch (\Throwable $e) {
                error_log('[MercadoPago] ' . $e->getMessage());
                $this->redirectTo(APP_URL . '/checkout/pagamento?id=' . $licencaId . '&h=' . $token . '&erro=1');
            }
            return;
        }

        // Pagar.me: pagamento vem via webhook, não por aqui
        if ($gateway === 'pagarme') {
            $_SESSION['checkout_licenca_id'] = $licencaId;
            $_SESSION['checkout_token']      = $this->token($licencaId);
            $this->redirectTo(APP_URL . '/checkout/pagamento?id=' . $licencaId . '&h=' . $token);
            return;
        }

        // Modo dev: ativa direto e passa id+token para a página de sucesso
        (new Licenca())->ativarAposPagamento($licencaId, $tipo, 'DEV-' . $licencaId);
        $this->redirectTo(APP_URL . '/checkout/sucesso?id=' . $licencaId . '&h=' . $this->token($licencaId));
    }

    // ----------------------------------------------------------------
    // POST /checkout/processar-pagamento — Checkout Transparente (Bricks)
    // ----------------------------------------------------------------

    public function processarPagamento(): void {
        header('Content-Type: application/json');

        $body      = json_decode(file_get_contents('php://input'), true) ?? [];
        $licencaId = (int)($body['licenca_id'] ?? 0);
        $token     = $body['checkout_token'] ?? '';

        if (!$licencaId || !hash_equals($this->token($licencaId), $token)) {
            echo json_encode(['status' => 'erro', 'mensagem' => 'Token inválido.']);
            exit;
        }

        $licenca = (new Licenca())->findById($licencaId);
        if (!$licenca) {
            echo json_encode(['status' => 'erro', 'mensagem' => 'Licença não encontrada.']);
            exit;
        }
        if ($licenca['status'] === 'ativa') {
            echo json_encode(['status' => 'approved']);
            exit;
        }

        $cfg         = new Configuracao();
        $accessToken = $cfg->get('mp_access_token');
        if (!$accessToken) {
            echo json_encode(['status' => 'erro', 'mensagem' => 'Gateway não configurado.']);
            exit;
        }

        $tipo  = $licenca['tipo'];
        $valor = $this->precos[$tipo] ?? PRECO_MENSAL;

        // Remove internal fields before forwarding to MP API
        $formData = $body;
        unset($formData['licenca_id'], $formData['checkout_token']);

        try {
            $resultado = (new MercadoPagoService($accessToken))->processarPagamento(
                $licencaId, $tipo, $valor,
                $licenca['email'] ?? '',
                $formData,
                APP_URL
            );

            // Pix pendente: não ativa ainda, devolve QR code para polling
            if ($resultado['status'] === 'pending' && !empty($resultado['qr_code'])) {
                echo json_encode($resultado); // contém qr_code, qr_code_base64
                exit;
            }

            // Aprovado (cartão/débito): ativa imediatamente e devolve URL de sucesso
            if ($resultado['status'] === 'approved') {
                (new Licenca())->ativarAposPagamento($licencaId, $tipo, $resultado['id']);
                $resultado['redirect_url'] = APP_URL . '/checkout/sucesso?id=' . $licencaId . '&h=' . $this->token($licencaId);
            }

            echo json_encode($resultado);
        } catch (\Throwable $e) {
            error_log('[MP Bricks] ' . $e->getMessage());
            echo json_encode(['status' => 'erro', 'mensagem' => 'Erro ao processar pagamento.']);
        }
        exit;
    }

    // ----------------------------------------------------------------
    // Polling JS — GET /checkout/status?id=X&h=Y
    // ----------------------------------------------------------------

    public function status(): void {
        header('Content-Type: application/json');
        $licencaId = (int)($_GET['id'] ?? 0);
        $token     = $_GET['h'] ?? '';

        if (!$licencaId || !hash_equals($this->token($licencaId), $token)) {
            echo json_encode(['status' => 'invalido']);
            exit;
        }

        $licenca = (new Licenca())->findById($licencaId);
        echo json_encode(['status' => $licenca['status'] ?? 'erro']);
        exit;
    }

    // ----------------------------------------------------------------
    // Páginas de resultado
    // ----------------------------------------------------------------

    public function sucesso(): void {
        // Tenta recuperar a licença pelo id+token (passado via query string ou sessão)
        $licencaId = (int)($_GET['id'] ?? $_SESSION['checkout_licenca_id'] ?? 0);
        $token     = $_GET['h'] ?? $_SESSION['checkout_token'] ?? '';

        $chave    = null;
        $pendente = false;

        if ($licencaId && $token && hash_equals($this->token($licencaId), $token)) {
            $licenca = (new Licenca())->findById($licencaId);
            if ($licenca) {
                if ($licenca['status'] === 'ativa') {
                    $chave = $licenca['chave'];
                } elseif ($licenca['status'] === 'pendente') {
                    // Webhook ainda não chegou (PIX / async) — JS vai fazer polling
                    $pendente = true;
                }
            }
        }

        // Limpa a sessão após usar
        unset($_SESSION['checkout_licenca_id'], $_SESSION['checkout_token']);

        $this->view('checkout.sucesso', [
            'chave'      => $chave,
            'pendente'   => $pendente,
            'licencaId'  => $licencaId,
            'token'      => $token,
        ], 'checkout');
    }

    public function cancelado(): void { $this->view('checkout.cancelado', [], 'checkout'); }

    // ----------------------------------------------------------------

    private function token(int $licencaId): string {
        return substr(hash_hmac('sha256', (string)$licencaId, API_SECRET), 0, 20);
    }
}
