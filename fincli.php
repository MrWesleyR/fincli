<?php
#!/usr/bin/env php
/**
 * FinCLI v2.6 STABLE - Unified Edition
 *
 * ORIGEM v1.0 (Base Estável)
 *   - Core Financeiro: Projeções, Recorrências, Inércia.
 *   - UI: Tabelas coloridas, Dashboards, Indicadores.
 *   - Auth: Autenticação via SQLite + Senha Mestra.
 *   - Self-Destruct Installer que se remove após configuração.
 *
 * FEATURES v2.1 (Preservadas Integralmente)
 *   - template_id nos movements (vínculo template→lançamento).
 *   - Remoção de recorrente: desativa template + remove futuros vinculados.
 *   - Ledger com saldo running e coluna Categoria.
 *   - addMove com seleção de categoria interativa.
 *   - Opção [7] Balanço Anual com top-10 e evolução mensal.
 *   - Opção [8] Análise Mensal com comparativo mês anterior.
 *   - Tabela analytics para métricas históricas.
 *   - UI helpers: info(), success(), error(), warning().
 *   - debugDump mostra coluna template_id.
 *
 * CORREÇÕES v2.2
 *   [BUG-01] Installer restaurado com bootstrap guard.
 *   [BUG-02] cleanDuplicates() dupla chamada por loop removida; chamada única dentro de sync().
 *   [BUG-03] SQL injection em ledger DELETE/SELECT e em consultas com $period/$year/$id corrigidos via prepared statements.
 *   [BUG-04] getBiggestExpense(): cálculo de monthsDiff corrigido — removido +1 hardcoded incorreto; usa date_diff correto via (y*12 + m).
 *   [BUG-05] saveMetric() removido do hot-path de getSummaryData(); métricas gravadas apenas em operações explícitas de relatório.
 *   [BUG-06] SELECT changes() substituído por PDOStatement::rowCount() para limpeza de duplicatas.
 *   [BUG-07] PDO::ATTR_AUTOCOMMIT => 1 removido (atributo inexistente no driver SQLite/PDO — era silently ignored e confuso).
 *   [BUG-08] drawSummaryBlock pad ajustado para acomodar labels longos da v2.1 sem overflow visual.
 *   [BUG-09] getAnnualReport() não salva mais blob JSON completo na analytics; salva apenas o balance como número.
 *   [BUG-10] sync(): check de deduplicação por template_id migrado da v2.1 mantido e validado.
 *   [BUG-11] Todas as queries com variáveis de usuário convertidas para prepared statements.
 *
 * CORREÇÕES v2.3
 *   [BUG-12] Dashboard e todos os menus agora sempre releem o banco — zero cache de estado.
 *            O loop principal invalida $dbMsg após cada exibição para evitar mensagens obsoletas.
 *   [BUG-13] getSummaryData() usava date('m') do sistema operacional para calcular meses futuros
 *            em vez de $this->period selecionado pelo usuário. Ao mudar período para meses
 *            passados/futuros, a projeção anual ficava errada. Corrigido: usa substr($this->period).
 *   [BUG-14] sync() agora respeita o período selecionado ($this->period) e não o mês real do SO.
 *            Isso permite navegar por períodos históricos sem contaminar outros meses com syncs.
 *   [BUG-15] Após qualquer operação mutante (ledger, addMove, manageTemplates), o loop força
 *            novo sync() antes do próximo dash() — garantindo que o dashboard sempre reflita
 *            o estado atual do banco sem resíduos de estado em memória.
 *
 * CORREÇÕES v2.4 — Robustez de Input (Anti-Travamento)
 *   [BUG-16] UI::ask() com fgets(STDIN)=false (STDIN fechado/pipe/SSH cai) causava loop
 *            infinito. Corrigido: fgets retornando false dispara exit(0) gracioso.
 *   [BUG-17] Descrição vazia aceita silenciosamente em addMove. Agora validada com reloop.
 *   [BUG-18] Valor inválido (letras, negativo, zero) em ledger edit persiste R$0,00.
 *            Agora validado — edição abortada com mensagem clara.
 *   [BUG-19] Data inválida em ledger edit (formato errado, data impossível) causava period
 *            corrompido no banco ("abc" → lançamento desaparecia do período). Agora validada
 *            com regex + checkdate() antes de persistir.
 *   [BUG-20] ID inexistente em manageTemplates [E] seguia silenciosamente com $curr=[],
 *            abrindo formulário em branco sem aviso. Agora detectado e rejeitado.
 *   [BUG-21] ID inexistente em manageTemplates [D] executava UPDATE/DELETE com id=0 sem
 *            feedback. Agora verifica existência antes de confirmar remoção.
 *   [BUG-22] Tipo inválido (diferente de IN/OUT) em manageTemplates aceito no banco.
 *            Agora rejeita com reloop.
 *   [BUG-23] Dia de vencimento inválido (0, 32, letras) aceito silenciosamente.
 *            Agora validado no range 1–31.
 *   [BUG-24] Formato de período inválido em start_date/end_date/targetPeriod/análise
 *            (ex: "abc") entrava no banco e quebrava comparações de string AAAA-MM.
 *            Validado com regex AAAA-MM em todos os pontos de entrada.
 *   [BUG-25] monthlyAnalysis: strtotime() de período inválido retornava false → date()
 *            produzia "1969-12". Agora validado antes de executar.
 *   [BUG-26] annualBalance: ano inválido (letras) convertido silenciosamente para 0.
 *            Agora validado como 4 dígitos numéricos.
 *
 * CORREÇÕES v2.5 — Regras de Negócio
 *   [BUG-R01] ROOT CAUSE do bug "remove no livro mas volta no dashboard":
 *            sync() recriava lançamentos OUT_FIX/IN_FIX deletados manualmente porque
 *            só checava template_id no período — sem memória da supressão explícita.
 *            Solução: tabela sync_suppressions(template_id, period). Quando o usuário
 *            deleta um lançamento fixo no livro caixa, uma supressão é gravada.
 *            O sync() respeita a supressão e não recria. Desativar/deletar o template
 *            limpa as supressões associadas.
 *   [BUG-R02] cleanDuplicates() chamada a cada render do dashboard podia deletar lançamentos
 *            editados pelo usuário (valor/descrição alterados) se havia outro com mesmo
 *            (period,description,type,amount,date). Critério endurecido: inclui template_id
 *            na chave de deduplicação, e lançamentos com status='MANUAL' são sempre preservados.
 *   [BUG-R03] manageTemplates [D] usava $this->eng->period como limite inferior do DELETE,
 *            então ao navegar em período histórico (ex: 2024-01) futuros de 2025 sobreviviam.
 *            Corrigido: remove TODOS os lançamentos futuros a partir do mínimo entre
 *            $this->eng->period e o mês atual do sistema.
 *   [BUG-R04] ledger delete de lançamento fixo: o usuário não conseguia remover uma despesa
 *            fixa de um mês específico — sync() a recriava imediatamente. Resolvido pela
 *            supressão de R-01. Ledger agora detecta o tipo do lançamento e grava supressão
 *            automaticamente ao deletar qualquer lançamento de origem de template (*_FIX).
 *   [BUG-R05] getSummaryData() projeção anual double-counting: meses futuros que já tinham
 *            lançamentos sincronizados (via navegação de período) eram somados duas vezes —
 *            uma via movements e outra via loop de templates. Corrigido: projeção só adiciona
 *            via template para meses que ainda não têm movimento sincronizado.
 *   [BUG-R06] cleanDuplicates() deletava um dos lançamentos quando dois templates distintos
 *            tinham mesma (description, type, amount) no mesmo período. Corrigido pela
 *            inclusão de template_id na chave de deduplicação.
 *   [BUG-R07] Installer ainda exibia "FinCLI v2.2 Stable". Corrigido para v2.5.
 *
 * CORREÇÕES v2.6 — Crash, Regras de Negócio e Performance
 *
 *   [BUG-A] CRASH: ALTER TABLE ADD COLUMN ... DEFAULT CURRENT_TIMESTAMP é rejeitado
 *           pelo SQLite — CURRENT_TIMESTAMP não é uma constante literal, é uma função.
 *           SQLite só aceita valores literais em ADD COLUMN DEFAULT.
 *           Corrigido: ADD COLUMN updated_at TEXT DEFAULT NULL (sem default dinâmico).
 *           Coluna existe apenas como metadado de auditoria — valor NULL em registros
 *           legados é semanticamente correto ("nunca editado").
 *
 *   [BUG-E] N+1 queries no sync(): prepare() chamado dentro do loop por template.
 *           Para 10 templates = 20 prepares + 20 executes por render do dashboard.
 *           Corrigido: suppression check movido para fora do loop via IN-clause única
 *           que carrega todos os template_ids suprimidos do período em um array,
 *           e check dentro do loop é apenas in_array() — zero queries extras.
 *
 *   [BUG-F] getBiggestExpense() buscava template por description em vez de template_id.
 *           Dois templates com mesma descrição retornavam o primeiro arbitrariamente,
 *           distorcendo a projeção de impacto financeiro.
 *           Corrigido: JOIN direto movements→recurring_templates por template_id.
 *
 *   [BUG-G] manageTemplates [E]: editar template (valor, descrição, categoria) não
 *           atualizava os lançamentos SYNCED já materializados no período atual.
 *           Resultado: dashboard mostrava valor antigo até o próximo ciclo completo.
 *           Corrigido: após UPDATE no template, UPDATE em cascade nos movements
 *           WHERE template_id=? AND status='SYNCED' AND period >= hoje.
 *           Lançamentos MANUAL editados pelo usuário (status≠SYNCED) são preservados.
 *
 *   [BUG-H] cleanDuplicates() executava DELETE full-table scan a cada render do
 *           dashboard mesmo sem duplicatas. Corrigido: executa apenas quando
 *           needsResync=true (após mutações) — não em cada ciclo de leitura.
 *
 *   [BUG-I] Projeção de meses futuros ignorava sync_suppressions: um mês suprimido
 *           não tinha movement mas também não deveria projetar via template.
 *           Corrigido: getSummaryData() e detailedProjection() consultam suppressions
 *           antes de somar template para mês futuro sem movements.
 *
 *   [BUG-K] Editar template não limpava supressões de períodos entre o start_date
 *           antigo e o novo. Supressões órfãs podiam impedir re-sincronização.
 *           Corrigido: clearSuppressions() chamado antes do UPDATE do template,
 *           permitindo que o sync() reavalie o estado limpo.
 *
 *   [BUG-L] debugDump não mostrava coluna status — impossível distinguir MANUAL
 *           de SYNCED. Corrigido: coluna status adicionada na listagem.
 */

date_default_timezone_set('America/Sao_Paulo');
define('DB_FILE', __DIR__ . '/fincli.db');
define('WIDTH', 85);

// ============================================================================
// UI ENGINE
// ============================================================================
class UI {
    public static function clear() { system(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'cls' : 'clear'); }
    public static function bold($t)   { return "\033[1m{$t}\033[0m"; }
    public static function cyan($t)   { return "\033[1;36m{$t}\033[0m"; }
    public static function green($t)  { return "\033[1;32m{$t}\033[0m"; }
    public static function red($t)    { return "\033[1;31m{$t}\033[0m"; }
    public static function grey($t)   { return "\033[1;30m{$t}\033[0m"; }
    public static function orange($t) { return "\033[38;5;208m{$t}\033[0m"; }
    public static function purple($t) { return "\033[1;35m{$t}\033[0m"; }
    public static function yellow($t) { return "\033[1;33m{$t}\033[0m"; }

    public static function stripColors($text) { return preg_replace('/\033\[[\d;]+m/', '', $text); }
    public static function currency($v) { return "R$ " . number_format((float)$v, 2, ',', '.'); }

    public static function percent($v) {
        $s = number_format((float)$v, 1, ',', '.') . "%";
        if ($v < 40) return self::green($s);
        if ($v < 75) return self::orange($s);
        return self::red($s);
    }

    public static function line() { echo "\033[1;30m" . str_repeat("─", WIDTH) . "\033[0m\n"; }

    // v2.1 helpers
    public static function info($text)    { echo self::cyan(" ℹ ") . self::grey($text) . "\n"; }
    public static function success($text) { echo self::green(" ✓ ") . $text . "\n"; }
    public static function error($text)   { echo self::red(" ✗ ") . $text . "\n"; }
    public static function warning($text) { echo self::yellow(" ⚠ ") . $text . "\n"; }

    public static function drawHeader($period, $currentUser, $dbStatusMsg = "") {
        self::clear();
        self::line();
        $userLabel   = "Usuário: " . self::bold($currentUser);
        $periodLabel = "Período: " . self::cyan(self::bold($period));
        $padding     = WIDTH - mb_strwidth(self::stripColors($userLabel . $periodLabel)) - 2;
        echo " " . $userLabel . str_repeat(" ", max(0, $padding)) . $periodLabel . " \n";
        echo " " . self::grey("DB: " . DB_FILE) . "\n";
        if (!empty($dbStatusMsg)) echo " " . $dbStatusMsg . "\n";
        self::line();
    }

    /**
     * [BUG-08] pad aumentado para 24 para acomodar labels da v2.1
     * como 'Acumulado/Projetado' (20 chars) sem overflow.
     */
    public static function drawSummaryBlock($title, $icon, $rows) {
        echo " " . $icon . " " . self::orange(self::bold($title)) . "\n";
        foreach ($rows as $label => $data) {
            $valRaw = $data['val'];
            $type   = $data['type'] ?? 'money';
            $color  = $data['color'] ?? null;

            if ($type === 'percent') {
                $valStr = self::percent($valRaw);
            } elseif ($type === 'raw') {
                $valStr = $color ? self::$color($valRaw) : $valRaw;
            } else {
                $valStr = self::currency($valRaw);
                if ($color) $valStr = self::$color($valStr);
            }
            $pad = 24 - mb_strwidth($label);
            echo " " . self::bold($label) . ":" . str_repeat(" ", max(1, $pad)) . $valStr . "\n";
        }
        self::line();
    }

    public static function tableRow($cols, $widths) {
        $line = "";
        foreach ($cols as $i => $c) {
            $w     = $widths[$i];
            $clean = self::stripColors($c);
            $printText = (mb_strwidth($clean) > $w)
                ? mb_strimwidth($clean, 0, $w, "..")
                : $c;
            $line .= $printText . str_repeat(" ", max(0, $w - mb_strwidth($clean))) . "  ";
        }
        echo " " . trim($line) . "\n";
    }

    public static function ask($prompt, $default = null, $hidden = false) {
        echo "\033[1;34m?\033[0m " . $prompt
             . ($default !== null ? " [" . self::bold($default) . "]" : "")
             . ": ";
        if ($hidden) {
            if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
                system('stty -echo');
                $raw = fgets(STDIN);
                system('stty echo');
                echo "\n";
            } else {
                $raw = fgets(STDIN);
            }
        } else {
            $raw = fgets(STDIN);
        }
        // [BUG-16] fgets retorna false quando STDIN fecha (pipe, SSH cai, EOF).
        // Sem esta guarda, o caller recebe "" e o loop de menu fica infinito.
        if ($raw === false) {
            echo "\n" . self::yellow(" [!] Entrada encerrada. Saindo.\n");
            exit(0);
        }
        $in = trim($raw);
        return ($in === "" && $default !== null) ? $default : $in;
    }

    // -------------------------------------------------------------------------
    // Validadores centralizados — usados por todos os submenus
    // -------------------------------------------------------------------------

    /**
     * Valida formato AAAA-MM e existência do mês (01-12).
     * [BUG-24] Períodos livres como "abc" quebravam comparações de string no SQLite.
     */
    public static function isValidPeriod($v) {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v)) return false;
        return true;
    }

    /**
     * Valida formato AAAA-MM-DD e que a data existe no calendário.
     * [BUG-19] Datas como "abc" ou "2024-13-99" corrompiam o campo period.
     */
    public static function isValidDate($v) {
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $v, $m)) return false;
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }

    /**
     * Valida valor monetário: numérico e maior que zero.
     * [BUG-18] Letras convertidas para 0.0 pelo cast (float) sem aviso.
     */
    public static function isValidAmount($v) {
        return is_numeric($v) && (float)$v > 0;
    }

    /**
     * Pede input em loop até receber valor que passe no $validator.
     * Evita repetição de while/if em cada submenu.
     */
    public static function askUntilValid($prompt, $validator, $errorMsg, $default = null) {
        while (true) {
            $val = self::ask($prompt, $default);
            if ($validator($val)) return $val;
            self::error($errorMsg);
            sleep(1);
        }
    }
}

// ============================================================================
// INSTALLER BLOCK (Self-Destruct — Feature v1.0/v12.0, restaurado no v2.2)
// [BUG-01] Bloco estava ausente na v2.1, causando fatal error em primeiro uso.
// ============================================================================
// <INSTALLER_BLOCK>
function runInstaller() {
    UI::clear(); UI::line();
    echo " " . UI::bold("🛠️  SETUP INICIAL (FinCLI v2.6 Stable)") . "\n";
    UI::line();
    echo UI::yellow(" Nenhuma base de dados encontrada. Vamos configurar.\n");

    $u   = UI::ask("Defina Usuário Admin");
    $p   = UI::ask("Defina Senha Admin", null, true);
    $dbP = UI::ask("Defina Senha Mestra do Banco (Simulação)", null, true);

    if (empty($u) || empty($p) || empty($dbP)) {
        die(UI::red("\n [!] Todos os campos são obrigatórios.\n"));
    }

    $hu  = password_hash($u,   PASSWORD_BCRYPT);
    $hp  = password_hash($p,   PASSWORD_BCRYPT);
    $hdb = password_hash($dbP, PASSWORD_BCRYPT);

    $db = new PDO('sqlite:' . DB_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Tabela de Segurança
    $db->exec("CREATE TABLE IF NOT EXISTS f_settings (key_name TEXT PRIMARY KEY, key_value TEXT)");
    $db->exec("INSERT OR REPLACE INTO f_settings VALUES ('hash_user', " . $db->quote($hu) . ")");
    $db->exec("INSERT OR REPLACE INTO f_settings VALUES ('hash_pass', " . $db->quote($hp) . ")");
    $db->exec("INSERT OR REPLACE INTO f_settings VALUES ('hash_db',   " . $db->quote($hdb) . ")");
    $db->exec("INSERT OR REPLACE INTO f_settings VALUES ('display_name', " . $db->quote($u) . ")");

    // Tabelas Financeiras (estrutura v2.1)
    $db->exec("CREATE TABLE IF NOT EXISTS movements (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        period      TEXT    NOT NULL,
        date        DATE    NOT NULL,
        description TEXT    NOT NULL,
        type        TEXT    NOT NULL,
        amount      DECIMAL NOT NULL,
        category    TEXT    NOT NULL,
        status      TEXT    DEFAULT 'PENDING',
        template_id INTEGER,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS recurring_templates (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        description  TEXT    NOT NULL,
        amount       DECIMAL NOT NULL,
        type         TEXT    NOT NULL,
        active       INT     DEFAULT 1,
        day_due      INT     DEFAULT 5,
        category     TEXT    NOT NULL,
        start_date   TEXT,
        end_date     TEXT,
        installments INT     DEFAULT 0,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS analytics (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        period          TEXT    NOT NULL,
        metric_type     TEXT    NOT NULL,
        metric_name     TEXT    NOT NULL,
        metric_value    DECIMAL NOT NULL,
        additional_data TEXT,
        calculated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(period, metric_type, metric_name)
    )");

    // [BUG-R01] Tabela de supressões: impede sync() de recriar lançamentos
    // que o usuário deletou explicitamente no livro caixa.
    $db->exec("CREATE TABLE IF NOT EXISTS sync_suppressions (
        template_id INTEGER NOT NULL,
        period      TEXT    NOT NULL,
        suppressed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (template_id, period)
    )");

    echo UI::green("\n [OK] Banco criado e tabelas estruturadas.");
    echo "\n [..] Removendo código de instalação deste script para segurança...\n";

    $source = file_get_contents(__FILE__);
    $source = preg_replace('/\/\/ <INSTALLER_BLOCK>.*?\/\/ <\/INSTALLER_BLOCK>/s', '', $source);
    $source = preg_replace('/\/\/ <BOOTSTRAP>.*?\/\/ <\/BOOTSTRAP>/s', '', $source);
    file_put_contents(__FILE__, $source);

    die(UI::green("\n [+] Pronto! O script agora é puramente operacional.\n Execute novamente para logar.\n"));
}
// </INSTALLER_BLOCK>

// <BOOTSTRAP>
if (!file_exists(DB_FILE)) {
    runInstaller();
    exit;
}
// </BOOTSTRAP>

// ============================================================================
// AUTH ENGINE (v1.0/v12.0 — inalterada, estável)
// ============================================================================
class Auth {
    private $db;
    public $currentUser = "Guest";

    public function __construct($db) { $this->db = $db; }

    public function login() {
        UI::clear(); UI::line();
        echo " " . UI::bold("🔒 ACESSO AO SISTEMA") . "\n";
        UI::line();

        try {
            $stmt     = $this->db->query("SELECT key_name, key_value FROM f_settings");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) {
            die(UI::red(" [!] Erro: Banco corrompido ou tabelas de sistema ausentes.\n"));
        }

        if (!$settings) die(UI::red(" [!] Erro Crítico: Configurações não encontradas.\n"));

        $user = UI::ask("Usuário");
        $pass = UI::ask("Senha", null, true);

        if (!password_verify($user, $settings['hash_user'])
         || !password_verify($pass, $settings['hash_pass'])) {
            die(UI::red(" [!] Acesso Negado.\n"));
        }

        UI::clear(); UI::line();
        echo " " . UI::bold("🗄️  DESBLOQUEIO DE DADOS") . "\n";
        UI::line();

        $dbPass = UI::ask("Senha do Banco", null, true);
        if (!password_verify($dbPass, $settings['hash_db'])) {
            die(UI::red(" [!] Senha do banco incorreta.\n"));
        }

        $this->currentUser = $settings['display_name'] ?? $user;
        echo UI::green(" [+] Autenticação confirmada. Carregando motor financeiro...\n");
        sleep(1);
    }
}

// ============================================================================
// FINANCIAL CORE ENGINE
// ============================================================================
class FinEngine {
    public  $db;
    public  $period;
    public  $dbMsg      = "";
    /**
     * [BUG-15] Flag que indica que dados foram mutados durante uma operação de submenu.
     * O loop principal em run() verifica este flag após route() retornar e, se verdadeiro,
     * executa sync() novamente antes do próximo dash() — garantindo dashboard sempre fresco.
     * Evita a necessidade de qualquer forma de cache invalidation explícita nos submenus.
     */
    public  $needsResync = false;

    public function __construct($pdoConnection) {
        $this->db = $pdoConnection;
        $this->init();
        $this->period = date('Y-m');
    }

    private function init() {
        // Idempotente — garante estrutura mesmo em migrações
        $this->db->exec("CREATE TABLE IF NOT EXISTS movements (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            period      TEXT    NOT NULL,
            date        DATE    NOT NULL,
            description TEXT    NOT NULL,
            type        TEXT    NOT NULL,
            amount      DECIMAL NOT NULL,
            category    TEXT    NOT NULL,
            status      TEXT    DEFAULT 'PENDING',
            template_id INTEGER,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->exec("CREATE TABLE IF NOT EXISTS recurring_templates (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            description  TEXT    NOT NULL,
            amount       DECIMAL NOT NULL,
            type         TEXT    NOT NULL,
            active       INT     DEFAULT 1,
            day_due      INT     DEFAULT 5,
            category     TEXT    NOT NULL,
            start_date   TEXT,
            end_date     TEXT,
            installments INT     DEFAULT 0,
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->exec("CREATE TABLE IF NOT EXISTS analytics (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            period          TEXT    NOT NULL,
            metric_type     TEXT    NOT NULL,
            metric_name     TEXT    NOT NULL,
            metric_value    DECIMAL NOT NULL,
            additional_data TEXT,
            calculated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(period, metric_type, metric_name)
        )");

        // [BUG-R01] Tabela de supressões — migração não-destrutiva para bancos antigos
        $this->db->exec("CREATE TABLE IF NOT EXISTS sync_suppressions (
            template_id   INTEGER NOT NULL,
            period        TEXT    NOT NULL,
            suppressed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (template_id, period)
        )");

        // Migração não-destrutiva via PRAGMA — garante colunas em bancos legados v1.0.
        // PRAGMA table_info é a forma canônica de checar existência de coluna em SQLite.
        // [BUG-A] SQLite rejeita DEFAULT CURRENT_TIMESTAMP em ALTER TABLE ADD COLUMN
        //         porque CURRENT_TIMESTAMP não é um literal constante — é uma função.
        //         Somente CREATE TABLE aceita defaults dinâmicos. Em ADD COLUMN,
        //         apenas literais como NULL, 0, '' são aceitos.
        //         Solução: updated_at DEFAULT NULL. NULL é semanticamente correto
        //         para registros legados ("nunca editado explicitamente").
        $cols     = $this->db->query("PRAGMA table_info(movements)")->fetchAll(PDO::FETCH_ASSOC);
        $colNames = array_column($cols, 'name');

        if (!in_array('template_id', $colNames, true)) {
            $this->db->exec("ALTER TABLE movements ADD COLUMN template_id INTEGER");
        }
        if (!in_array('updated_at', $colNames, true)) {
            // DEFAULT NULL — único valor literal válido para timestamps em ALTER TABLE
            $this->db->exec("ALTER TABLE movements ADD COLUMN updated_at TEXT DEFAULT NULL");
        }
        if (!in_array('status', $colNames, true)) {
            $this->db->exec("ALTER TABLE movements ADD COLUMN status TEXT DEFAULT 'PENDING'");
        }
    }

    /**
     * [BUG-06] Usa PDOStatement::rowCount() em vez de SELECT changes().
     * [BUG-02] Chamado APENAS de dentro de sync() — sem dupla chamada.
     * [BUG-R02] Critério endurecido: inclui template_id na chave de deduplicação
     *           para evitar apagar lançamentos de templates distintos com mesmo nome/valor.
     *           Lançamentos com status='MANUAL' são sempre preservados (nunca deduplicados).
     * [BUG-R06] Dois templates com mesma (description, type, amount) no mesmo período
     *           geravam duplicata falsa — template_id na chave resolve isso.
     */
    private function cleanDuplicates() {
        // Preserva MANUAL sempre. Para SYNCED, mantém o MAX(id) por chave composta
        // que inclui template_id — dois templates distintos nunca colidem.
        $stmt = $this->db->query("
            DELETE FROM movements
            WHERE status != 'MANUAL'
              AND id NOT IN (
                SELECT MAX(id)
                FROM movements
                WHERE status != 'MANUAL'
                GROUP BY period, description, type, amount, date,
                         COALESCE(template_id, -id)
            )
        ");
        $deleted = $stmt->rowCount();
        if ($deleted > 0) {
            $this->dbMsg = UI::yellow("⚠ $deleted duplicata(s) removida(s)");
        }
    }

    /**
     * [BUG-R01] Grava supressão para um template+período específico.
     * Chamado pelo ledger quando o usuário deleta um lançamento *_FIX.
     * Impede sync() de recriar o lançamento naquele período.
     */
    public function suppressSync(int $templateId, string $period) {
        $stmt = $this->db->prepare(
            "INSERT OR IGNORE INTO sync_suppressions (template_id, period) VALUES (?, ?)"
        );
        $stmt->execute([$templateId, $period]);
    }

    /**
     * [BUG-R01] Remove todas as supressões de um template (quando o próprio template
     * é deletado/desativado pelo usuário em manageTemplates — neste caso as supressões
     * são irrelevantes pois o template não sincroniza mais de qualquer forma).
     */
    public function clearSuppressions(int $templateId) {
        $stmt = $this->db->prepare(
            "DELETE FROM sync_suppressions WHERE template_id=?"
        );
        $stmt->execute([$templateId]);
    }

    /**
     * [BUG-02] cleanDuplicates chamado uma única vez, aqui.
     * [BUG-10] Deduplicação por template_id mantida.
     * [BUG-14] Opera sobre $this->period (período selecionado).
     * [BUG-R01] Respeita sync_suppressions — não recria lançamentos suprimidos.
     */
    public function sync() {
        // [BUG-H] cleanDuplicates() só é necessário após mutações de dados.
        // Antes rodava a cada render do dashboard (scan full-table sem duplicatas).
        // Agora é chamado apenas quando needsResync=true, controlado pelo caller.
        if ($this->needsResync) {
            $this->cleanDuplicates();
        }

        $templates = $this->db->query(
            "SELECT * FROM recurring_templates WHERE active=1"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($templates)) return;

        // [BUG-E] Carrega TODAS as supressões do período atual em uma única query.
        // Antes: prepare()+execute() dentro do loop = 2×N queries por sync().
        // Agora: 1 query → array em memória → in_array() O(n) no loop.
        $suppStmt = $this->db->prepare(
            "SELECT template_id FROM sync_suppressions WHERE period=?"
        );
        $suppStmt->execute([$this->period]);
        $suppressedIds = array_column($suppStmt->fetchAll(PDO::FETCH_ASSOC), 'template_id');
        $suppressedIds = array_map('intval', $suppressedIds);

        // Carrega movements existentes do período para evitar N+1 no check de existência
        $existStmt = $this->db->prepare(
            "SELECT template_id FROM movements WHERE period=? AND template_id IS NOT NULL"
        );
        $existStmt->execute([$this->period]);
        $existingTemplateIds = array_column($existStmt->fetchAll(PDO::FETCH_ASSOC), 'template_id');
        $existingTemplateIds = array_map('intval', $existingTemplateIds);

        $insertStmt = $this->db->prepare(
            "INSERT INTO movements (period, date, description, type, amount, category, status, template_id)
             VALUES (?,?,?,?,?,?,?,?)"
        );

        $syncCount = 0;
        foreach ($templates as $tpl) {
            if (!empty($tpl['start_date']) && $this->period < $tpl['start_date']) continue;
            if (!empty($tpl['end_date'])   && $this->period > $tpl['end_date'])   continue;

            // [BUG-R01] Verifica supressão — array lookup, zero queries
            if (in_array((int)$tpl['id'], $suppressedIds, true)) continue;

            // Já existe movement para este template neste período
            if (in_array((int)$tpl['id'], $existingTemplateIds, true)) continue;

            $moveType = ($tpl['type'] === 'IN') ? 'IN_FIX' : 'OUT_FIX';
            $day      = str_pad($tpl['day_due'] ?? '05', 2, '0', STR_PAD_LEFT);

            $insertStmt->execute([
                $this->period,
                $this->period . "-" . $day,
                $tpl['description'],
                $moveType,
                $tpl['amount'],
                $tpl['category'],
                'SYNCED',
                $tpl['id'],
            ]);
            $syncCount++;
        }

        if ($syncCount > 0) {
            $this->dbMsg = UI::green("✓ $syncCount lançamento(s) sincronizado(s)");
        }
    }

    /**
     * [BUG-05] saveMetric() removido do hot-path — dashboard não grava na analytics.
     * Métricas são salvas apenas em relatórios explícitos (getAnnualReport).
     */
    private function saveMetric($period, $type, $name, $value, $additionalData = null) {
        $stmt = $this->db->prepare(
            "INSERT OR REPLACE INTO analytics
             (period, metric_type, metric_name, metric_value, additional_data, calculated_at)
             VALUES (?, ?, ?, ?, ?, datetime('now'))"
        );
        // [BUG-09] additionalData nunca é o blob do report completo — apenas scalars
        $stmt->execute([$period, $type, $name, $value,
            is_scalar($additionalData) ? (string)$additionalData : null]);
    }

    /**
     * [BUG-04] Cálculo de monthsDiff corrigido.
     * [BUG-F]  JOIN por template_id em vez de busca por description.
     *          Dois templates com mesma descrição retornavam resultado arbitrário.
     */
    private function getBiggestExpense() {
        $year = substr($this->period, 0, 4);

        // Agrega por description+template_id para não colidir templates distintos
        $stmt = $this->db->prepare("
            SELECT
                m.description,
                m.category,
                m.template_id,
                COUNT(*)      AS occurrences,
                SUM(m.amount) AS total_amount,
                AVG(m.amount) AS avg_amount,
                MIN(m.period) AS first_period,
                MAX(m.period) AS last_period
            FROM movements m
            WHERE m.period LIKE ? AND m.type LIKE 'OUT%' AND m.amount > 0
            GROUP BY m.description, m.template_id
            ORDER BY total_amount DESC
        ");
        $stmt->execute(["$year-%"]);
        $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($expenses)) {
            return ['desc' => 'N/A', 'val' => 0, 'acc' => 0,
                    'months' => 0, 'avg' => 0, 'category' => 'N/A', 'occurrences' => 0];
        }

        $biggest      = null;
        $biggestScore = 0;

        // Pré-carrega templates ativos para evitar N+1
        $tplRows = $this->db->query(
            "SELECT id, end_date FROM recurring_templates WHERE active=1"
        )->fetchAll(PDO::FETCH_ASSOC);
        $tplMap = [];
        foreach ($tplRows as $row) {
            $tplMap[(int)$row['id']] = $row;
        }

        foreach ($expenses as $exp) {
            $firstDate  = new DateTime($exp['first_period'] . "-01");
            $lastDate   = new DateTime($exp['last_period']  . "-01");
            $diff       = $firstDate->diff($lastDate);
            $monthsDiff = ($diff->y * 12) + $diff->m;

            $futureMonths = 0;
            // [BUG-F] Lookup por template_id — sem ambiguidade por nome
            $tplId = (int)($exp['template_id'] ?? 0);
            if ($tplId > 0 && isset($tplMap[$tplId])) {
                $tpl     = $tplMap[$tplId];
                $endDate = !empty($tpl['end_date'])
                    ? new DateTime($tpl['end_date'] . "-01")
                    : new DateTime("$year-12-01");
                $currentDate = new DateTime($this->period . "-01");
                if ($currentDate < $endDate) {
                    $fd           = $currentDate->diff($endDate);
                    $futureMonths = ($fd->y * 12) + $fd->m;
                }
            }

            $totalMonths    = $monthsDiff + $futureMonths;
            $projectedTotal = (float)$exp['total_amount'] + ($futureMonths * (float)$exp['avg_amount']);
            $score          = $totalMonths * (float)$exp['avg_amount'];

            if ($score > $biggestScore) {
                $biggestScore = $score;
                $biggest = [
                    'desc'        => $exp['description'],
                    'val'         => (float)$exp['avg_amount'],
                    'acc'         => $projectedTotal,
                    'months'      => $totalMonths,
                    'avg'         => (float)$exp['avg_amount'],
                    'category'    => $exp['category'],
                    'occurrences' => (int)$exp['occurrences'],
                ];
            }
        }

        return $biggest;
    }

    /**
     * [BUG-05] saveMetric() removido deste método — zero I/O extra no hot-path do dashboard.
     * [BUG-13] currMonthNum derivado de $this->period (período selecionado), NÃO de date('m').
     *          Antes, ao navegar para um período passado como 2024-03, o loop de projeção
     *          usava o mês real do SO (ex: 6) e projetava apenas meses 7-12, ignorando
     *          os meses 4-6 que já eram "futuros" em relação ao período selecionado.
     *          Agora projeta corretamente a partir do mês do período escolhido.
     */
    public function getSummaryData() {
        $year         = substr($this->period, 0, 4);
        // [BUG-13] usa o mês do período selecionado, não do relógio do sistema
        $currMonthNum = (int)substr($this->period, 5, 2);

        $stIn  = $this->db->prepare("SELECT SUM(amount) FROM movements WHERE period=? AND type LIKE 'IN%'");
        $stOut = $this->db->prepare("SELECT SUM(amount) FROM movements WHERE period=? AND type LIKE 'OUT%'");
        $stIn->execute([$this->period]);
        $stOut->execute([$this->period]);

        $inM  = (float)($stIn->fetchColumn()  ?: 0);
        $outM = (float)($stOut->fetchColumn() ?: 0);
        $balM = $inM - $outM;
        $debtM = $inM > 0 ? ($outM / $inM) * 100 : 0;

        // Projeção anual: real acumulado + templates dos meses futuros
        $stInY  = $this->db->prepare("SELECT SUM(amount) FROM movements WHERE period LIKE ? AND type LIKE 'IN%'");
        $stOutY = $this->db->prepare("SELECT SUM(amount) FROM movements WHERE period LIKE ? AND type LIKE 'OUT%'");
        $stInY->execute(["$year-%"]);
        $stOutY->execute(["$year-%"]);

        $projIn  = (float)($stInY->fetchColumn()  ?: 0);
        $projOut = (float)($stOutY->fetchColumn() ?: 0);

        $tpls = $this->db->query(
            "SELECT id, amount, type, start_date, end_date FROM recurring_templates WHERE active=1"
        )->fetchAll(PDO::FETCH_ASSOC);

        $stHasMov = $this->db->prepare(
            "SELECT 1 FROM movements WHERE period=? LIMIT 1"
        );
        // [BUG-I] Carrega supressões de meses futuros para não projetar template suprimido
        $stFutureSupp = $this->db->prepare(
            "SELECT period FROM sync_suppressions WHERE template_id=? AND period > ?"
        );

        for ($i = $currMonthNum + 1; $i <= 12; $i++) {
            $p = "$year-" . str_pad($i, 2, '0', STR_PAD_LEFT);
            $stHasMov->execute([$p]);
            if ($stHasMov->fetch()) continue; // já tem movements reais — não somar duas vezes

            foreach ($tpls as $t) {
                if (!empty($t['start_date']) && $p < $t['start_date']) continue;
                if (!empty($t['end_date'])   && $p > $t['end_date'])   continue;

                // [BUG-I] Verifica supressão para este template neste mês futuro
                $chkS = $this->db->prepare(
                    "SELECT 1 FROM sync_suppressions WHERE template_id=? AND period=?"
                );
                $chkS->execute([(int)$t['id'], $p]);
                if ($chkS->fetch()) continue; // suprimido — não projetar

                if ($t['type'] == 'IN') $projIn  += $t['amount'];
                else                    $projOut += $t['amount'];
            }
        }

        $balY  = $projIn - $projOut;
        $debtY = $projIn > 0 ? ($projOut / $projIn) * 100 : 0;

        $maxExp = $this->getBiggestExpense();

        return [
            'month' => ['in' => $inM, 'out' => $outM, 'bal' => $balM, 'debt' => $debtM],
            'year'  => ['in' => $projIn, 'out' => $projOut, 'bal' => $balY, 'debt' => $debtY],
            'max'   => $maxExp,
        ];
    }

    /**
     * [BUG-09] saveMetric salva apenas o balance (scalar), não o blob JSON do report.
     * [BUG-03] Queries com $year via prepared statement.
     */
    public function getAnnualReport($year) {
        $year = (string)(int)$year; // sanitiza

        $stInY  = $this->db->prepare("SELECT SUM(amount) FROM movements WHERE period LIKE ? AND type LIKE 'IN%'");
        $stOutY = $this->db->prepare("SELECT SUM(amount) FROM movements WHERE period LIKE ? AND type LIKE 'OUT%'");
        $stInY->execute(["$year-%"]);
        $stOutY->execute(["$year-%"]);

        $totalIncome  = (float)($stInY->fetchColumn()  ?: 0);
        $totalExpense = (float)($stOutY->fetchColumn() ?: 0);
        $balance      = $totalIncome - $totalExpense;

        $catStmt = $this->db->prepare("
            SELECT
                category,
                CASE WHEN type LIKE 'IN%' THEN 'IN' ELSE 'OUT' END AS type,
                COUNT(*)    AS count,
                SUM(amount) AS total,
                AVG(amount) AS average
            FROM movements
            WHERE period LIKE ?
            GROUP BY category, (CASE WHEN type LIKE 'IN%' THEN 'IN' ELSE 'OUT' END)
            ORDER BY total DESC
        ");
        $catStmt->execute(["$year-%"]);
        $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

        $monthlyStmt = $this->db->prepare("
            SELECT
                period,
                SUM(CASE WHEN type LIKE 'IN%'  THEN amount ELSE 0 END) AS income,
                SUM(CASE WHEN type LIKE 'OUT%' THEN amount ELSE 0 END) AS expense
            FROM movements
            WHERE period LIKE ?
            GROUP BY period
            ORDER BY period
        ");
        $monthlyStmt->execute(["$year-%"]);
        $monthly = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);

        $topStmt = $this->db->prepare("
            SELECT description, amount, date, category
            FROM movements
            WHERE period LIKE ? AND type LIKE 'OUT%'
            ORDER BY amount DESC
            LIMIT 10
        ");
        $topStmt->execute(["$year-%"]);
        $topExpenses = $topStmt->fetchAll(PDO::FETCH_ASSOC);

        // [BUG-09] Salva apenas o scalar balance, não o objeto completo
        $this->saveMetric($year, 'annual_report', 'balance', $balance);

        return [
            'year'          => $year,
            'total_income'  => $totalIncome,
            'total_expense' => $totalExpense,
            'balance'       => $balance,
            'categories'    => $categories,
            'monthly'       => $monthly,
            'top_expenses'  => $topExpenses,
        ];
    }
}

// ============================================================================
// APP CONTROLLER
// ============================================================================
class FinApp {
    private $eng;
    private $auth;
    private $pdo;

    public function __construct() {
        // [BUG-07] PDO::ATTR_AUTOCOMMIT removido — inexistente no SQLite driver.
        // SQLite em PDO já opera em autocommit fora de transações explícitas.
        $this->pdo = new PDO('sqlite:' . DB_FILE, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->auth = new Auth($this->pdo);
    }

    public function run() {
        $this->auth->login();
        $this->eng = new FinEngine($this->pdo);

        while (true) {
            // [BUG-12/15] sync() relê templates do banco a cada iteração — sem cache.
            // cleanDuplicates() é chamado dentro de sync().
            // $dbMsg é consumido pelo dash() e imediatamente zerado para evitar
            // que mensagens de operações anteriores apareçam em telas futuras.
            $this->eng->sync();
            $this->dash();
            $this->eng->dbMsg = ""; // invalida após exibição — próximo dash começa limpo
            $rawChoice = fgets(STDIN);
            if ($rawChoice === false) { echo "\n" . UI::yellow(" [!] Entrada encerrada. Saindo.\n"); exit(0); }
            $this->route(trim($rawChoice));

            // [BUG-15] Se qualquer submenu mutou dados (ledger, addMove, manageTemplates),
            // força novo sync() antes do próximo render do dashboard.
            // Isso garante que edições no livro caixa apareçam imediatamente nos cálculos.
            if ($this->eng->needsResync) {
                $this->eng->needsResync = false;
                $this->eng->sync();
            }
        }
    }

    private function dash() {
        $d = $this->eng->getSummaryData();
        UI::drawHeader($this->eng->period, $this->auth->currentUser, $this->eng->dbMsg);

        UI::drawSummaryBlock("Resumo Mensal", "💰", [
            'Receitas'        => ['val' => $d['month']['in'],  'color' => 'green'],
            'Despesas'        => ['val' => $d['month']['out'], 'color' => 'orange'],
            'Saldo'           => ['val' => $d['month']['bal'], 'color' => 'cyan'],
            'Comprometimento' => ['val' => $d['month']['debt'], 'type' => 'percent'],
        ]);

        UI::drawSummaryBlock("Performance Anual " . UI::grey("(PROJETADO)"), "📊", [
            'Receita Total' => ['val' => $d['year']['in'],  'color' => 'green'],
            'Despesa Total' => ['val' => $d['year']['out'], 'color' => 'red'],
            'Saldo Final'   => ['val' => $d['year']['bal'], 'color' => 'cyan'],
        ]);

        $maxD        = mb_strimwidth($d['max']['desc'], 0, 22, "..");
        $durationInfo = $d['max']['months'] > 0 ? " × {$d['max']['months']} meses" : "";
        UI::drawSummaryBlock(
            "Maior Impacto Financeiro" . UI::grey($durationInfo),
            "🔍",
            [
                "Conta: $maxD"       => ['val' => $d['max']['avg'], 'color' => 'purple'],
                'Acumulado/Projetado' => ['val' => $d['max']['acc'], 'color' => 'yellow'],
                'Categoria'           => ['val' => $d['max']['category'], 'type' => 'raw', 'color' => 'grey'],
            ]
        );

        echo " " . UI::bold("[1]") . " Saída Variável       " . UI::bold("[5]") . " Fluxo Detalhado\n";
        echo " " . UI::bold("[2]") . " Gestão Recorrentes    " . UI::bold("[6]") . " Mudar Período\n";
        echo " " . UI::bold("[3]") . " Receita Extra         " . UI::bold("[7]") . " 📈 Balanço Anual\n";
        echo " " . UI::bold("[4]") . " Livro Caixa (Editar)  " . UI::bold("[8]") . " 📊 Análise Mensal\n";
        echo UI::grey(" [9] DEBUG: Listar Tudo    [0] Sair") . "\n";
        UI::line();
        echo " Escolha: ";
    }

    private function route($o) {
        switch ($o) {
            case '1': $this->addMove('OUT_VAR');  break;
            case '2': $this->manageTemplates();   break;
            case '3': $this->addMove('IN_VAR');   break;
            case '4': $this->ledger();             break;
            case '5': $this->detailedProjection(); break;
            case '6':
                // [BUG-24] Período livre digitado aqui poderia corromper todo o fluxo.
                $newPeriod = UI::askUntilValid(
                    "Novo Período (AAAA-MM)",
                    fn($v) => UI::isValidPeriod($v),
                    "Formato inválido. Use AAAA-MM (ex: 2025-06).",
                    $this->eng->period
                );
                $this->eng->period = $newPeriod;
                break;
            case '7': $this->annualBalance();      break;
            case '8': $this->monthlyAnalysis();    break;
            case '9': $this->debugDump();          break;
            case '0': exit(0);
        }
    }

    // -------------------------------------------------------------------------
    // DEBUG (v1.0 preservado, coluna template_id da v2.1 mantida)
    // -------------------------------------------------------------------------
    private function debugDump() {
        UI::clear();
        UI::line();
        echo UI::red(" DEBUG DUMP - ÚLTIMOS 50 REGISTROS GERAIS\n");
        UI::line();
        $list = $this->eng->db
            ->query("SELECT * FROM movements ORDER BY date DESC LIMIT 50")
            ->fetchAll(PDO::FETCH_ASSOC);

        if (empty($list)) {
            echo UI::red(" O banco de dados de movimentos está VAZIO.\n");
        } else {
            // [BUG-L] Adicionada coluna Status para distinguir MANUAL de SYNCED
            UI::tableRow(["ID", "Período", "Data", "Descrição", "Valor", "Status", "Tpl"], [4, 8, 11, 18, 12, 7, 4]);
            foreach ($list as $l) {
                $statusStr = match($l['status'] ?? 'PENDING') {
                    'MANUAL' => UI::green('MANUAL'),
                    'SYNCED' => UI::cyan('SYNCED'),
                    default  => UI::grey($l['status'] ?? '-'),
                };
                UI::tableRow([
                    $l['id'],
                    $l['period'],
                    $l['date'],
                    $l['description'],
                    UI::currency($l['amount']),
                    $statusStr,
                    $l['template_id'] ?? '-',
                ], [4, 8, 11, 18, 12, 7, 4]);
            }
        }
        UI::line();
        UI::ask("Enter para voltar");
    }

    // -------------------------------------------------------------------------
    // ADD MOVE (v2.1 — com seleção de categoria interativa)
    // -------------------------------------------------------------------------
    private function addMove($type) {
        UI::clear();
        UI::line();
        echo " " . UI::bold($type == 'IN_VAR' ? "➕ ADICIONAR RECEITA EXTRA" : "➖ ADICIONAR DESPESA VARIÁVEL") . "\n";
        UI::line();

        // [BUG-17] Descrição vazia era aceita silenciosamente e gravada no banco.
        $desc = UI::askUntilValid(
            "Descrição",
            fn($v) => trim($v) !== '',
            "Descrição não pode ser vazia."
        );

        // [BUG-18] Letras e zero eram silenciosamente convertidos para 0.0.
        $val = UI::askUntilValid(
            "Valor (ex: 1500.00)",
            fn($v) => UI::isValidAmount($v),
            "Valor inválido. Use números maiores que zero (ex: 1500.00)."
        );

        echo "\n";
        UI::info("CATEGORIAS servem para organizar e analisar seus gastos/receitas.");
        if ($type == 'IN_VAR') {
            UI::info("Exemplos: Freelance, Bônus, Presente, Venda, Investimento, Extra");
            $cat = UI::ask("Categoria da Receita", "Extra");
        } else {
            UI::info("Exemplos: Alimentação, Transporte, Lazer, Saúde, Educação, Compras");
            $cat = UI::ask("Categoria da Despesa", "Variável");
        }
        if (trim($cat) === '') $cat = ($type == 'IN_VAR') ? 'Extra' : 'Variável';

        $stmt = $this->eng->db->prepare(
            "INSERT INTO movements (period, date, description, type, amount, category, status)
             VALUES (?,?,?,?,?,?,?)"
        );
        $stmt->execute([$this->eng->period, date('Y-m-d'), trim($desc), $type, (float)$val, trim($cat), 'MANUAL']);
        $this->eng->needsResync = true;
        UI::success("Lançamento adicionado com sucesso!");
        sleep(1);
    }

    // -------------------------------------------------------------------------
    // MANAGE TEMPLATES (v2.1 — lógica de remoção com futuros vinculados)
    // [BUG-03] Queries com $id via prepared statement
    // -------------------------------------------------------------------------
    private function manageTemplates() {
        while (true) {
            UI::clear();
            UI::line();
            echo " GESTÃO DE RECORRÊNCIAS (FIXAS / PARCELADOS)\n";
            UI::line();

            $items = $this->eng->db
                ->query("SELECT * FROM recurring_templates WHERE active=1 ORDER BY type DESC")
                ->fetchAll(PDO::FETCH_ASSOC);

            if (empty($items)) {
                echo UI::yellow(" Nenhuma recorrência cadastrada ainda.\n");
            } else {
                UI::tableRow(["ID", "Tipo", "Descrição", "Valor", "Início", "Fim"], [4, 6, 22, 12, 10, 10]);
                foreach ($items as $i) {
                    $typeStr = $i['type'] == 'IN' ? UI::green("INC") : UI::red("EXP");
                    UI::tableRow([
                        $i['id'],
                        $typeStr,
                        $i['description'],
                        UI::currency($i['amount']),
                        $i['start_date'] ?? "-",
                        $i['end_date']   ?? "Vitalício",
                    ], [4, 6, 22, 12, 10, 10]);
                }
            }

            UI::line();
            $opt = strtoupper(UI::ask("[N]ovo, [E]ditar, [D]eletar ou [V]oltar", "V"));
            if ($opt == 'V') break;

            if ($opt == 'N' || $opt == 'E') {
                $curr = [];
                $id   = null;

                if ($opt == 'E') {
                    // [BUG-20] ID inexistente seguia silenciosamente com $curr=[] sem aviso.
                    $idRaw = UI::ask("ID para Editar");
                    if (!ctype_digit($idRaw) || (int)$idRaw <= 0) {
                        UI::error("ID inválido.");
                        sleep(1);
                        continue;
                    }
                    $st = $this->eng->db->prepare(
                        "SELECT * FROM recurring_templates WHERE id=? AND active=1"
                    );
                    $st->execute([(int)$idRaw]);
                    $curr = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$curr) {
                        UI::error("Recorrência ID $idRaw não encontrada.");
                        sleep(1);
                        continue;
                    }
                    $id   = (int)$idRaw;
                    $curr = (array)$curr;
                }

                // [BUG-22] Tipo livre aceitava qualquer string no banco.
                $t = UI::askUntilValid(
                    "Tipo (IN/OUT)",
                    fn($v) => in_array(strtoupper($v), ['IN', 'OUT'], true),
                    "Tipo inválido. Digite IN (receita) ou OUT (despesa).",
                    $curr['type'] ?? 'OUT'
                );
                $t = strtoupper($t);

                $d = UI::askUntilValid(
                    "Descrição",
                    fn($v) => trim($v) !== '',
                    "Descrição não pode ser vazia.",
                    $curr['description'] ?? null
                );

                // [BUG-18] Valor inválido aceito silenciosamente.
                $v = UI::askUntilValid(
                    "Valor (ex: 1200.00)",
                    fn($v) => UI::isValidAmount($v),
                    "Valor inválido. Use números maiores que zero.",
                    isset($curr['amount']) ? (string)$curr['amount'] : null
                );

                // [BUG-23] Dia 0, 32 ou letras aceitos silenciosamente.
                $day = UI::askUntilValid(
                    "Dia de Vencimento (1-31)",
                    fn($v) => ctype_digit($v) && (int)$v >= 1 && (int)$v <= 31,
                    "Dia inválido. Informe um número entre 1 e 31.",
                    isset($curr['day_due']) ? (string)$curr['day_due'] : '05'
                );

                // [BUG-24] Período formato livre quebrava comparações de string no banco.
                $start = UI::askUntilValid(
                    "Início (AAAA-MM)",
                    fn($v) => UI::isValidPeriod($v),
                    "Formato inválido. Use AAAA-MM (ex: 2025-01).",
                    $curr['start_date'] ?? $this->eng->period
                );

                // Fim é opcional — Enter aceita vitalício, mas se digitar algo deve ser válido.
                while (true) {
                    $end = UI::ask("Fim (AAAA-MM) ou Enter para vitalício", $curr['end_date'] ?? '');
                    if ($end === '' || UI::isValidPeriod($end)) break;
                    UI::error("Formato inválido. Use AAAA-MM ou pressione Enter para vitalício.");
                    sleep(1);
                }
                // Valida que fim >= início quando informado
                if ($end !== '' && $end < $start) {
                    UI::error("Data fim ($end) não pode ser anterior ao início ($start). Campo ignorado.");
                    sleep(2);
                    $end = '';
                }

                echo "\n";
                UI::info("CATEGORIA organiza esta recorrência nos relatórios.");
                if ($t == 'IN') {
                    UI::info("Exemplos: Salário, Aluguel Recebido, Pensão, Investimento");
                    $cat = UI::ask("Categoria", $curr['category'] ?? 'Fixa');
                } else {
                    UI::info("Exemplos: Aluguel, Condomínio, Luz, Água, Internet, Plano de Saúde");
                    $cat = UI::ask("Categoria", $curr['category'] ?? 'Despesa Fixa');
                }
                if (trim($cat) === '') $cat = ($t == 'IN') ? 'Fixa' : 'Despesa Fixa';

                if ($opt == 'N') {
                    $stmt = $this->eng->db->prepare(
                        "INSERT INTO recurring_templates (type, description, amount, day_due, start_date, end_date, category)
                         VALUES (?,?,?,?,?,?,?)"
                    );
                    $stmt->execute([$t, trim($d), (float)$v, (int)$day, $start, $end ?: null, trim($cat)]);
                    $this->eng->needsResync = true;
                    UI::success("Recorrência criada!");
                } else {
                    // [BUG-K] Limpa supressões antes de editar o template.
                    // Se o usuário mudou datas ou valores, as supressões antigas podem
                    // bloquear sync() indevidamente nos novos períodos.
                    $this->eng->clearSuppressions($id);

                    $stmt = $this->eng->db->prepare(
                        "UPDATE recurring_templates SET type=?, description=?, amount=?, day_due=?, start_date=?, end_date=?, category=? WHERE id=?"
                    );
                    $stmt->execute([$t, trim($d), (float)$v, (int)$day, $start, $end ?: null, trim($cat), $id]);

                    // [BUG-G] Atualiza em cascade os lançamentos SYNCED já materializados
                    // a partir do período atual. Lançamentos MANUAL (editados pelo usuário)
                    // são preservados — o usuário ajustou intencionalmente aquele valor.
                    $realNow = date('Y-m');
                    $cascade = min($this->eng->period, $realNow);
                    $stCascade = $this->eng->db->prepare(
                        "UPDATE movements
                         SET description=?, amount=?, category=?, type=?
                         WHERE template_id=? AND status='SYNCED' AND period >= ?"
                    );
                    $moveType = ($t === 'IN') ? 'IN_FIX' : 'OUT_FIX';
                    $stCascade->execute([trim($d), (float)$v, trim($cat), $moveType, $id, $cascade]);

                    $this->eng->needsResync = true;
                    UI::success("Recorrência atualizada!");
                }
                sleep(1);

            } elseif ($opt == 'D') {
                // [BUG-21] ID inexistente executava UPDATE/DELETE com id=0 sem feedback.
                $idRaw = UI::ask("ID para remover");
                if (!ctype_digit($idRaw) || (int)$idRaw <= 0) {
                    UI::error("ID inválido.");
                    sleep(1);
                    continue;
                }
                $stCheck = $this->eng->db->prepare(
                    "SELECT id, description FROM recurring_templates WHERE id=? AND active=1"
                );
                $stCheck->execute([(int)$idRaw]);
                $rec = $stCheck->fetch(PDO::FETCH_ASSOC);
                if (!$rec) {
                    UI::error("Recorrência ID $idRaw não encontrada.");
                    sleep(1);
                    continue;
                }

                UI::warning("ATENÇÃO: Remover recorrência irá:");
                echo "  • Desativar o template: " . UI::bold($rec['description']) . "\n";
                echo "  • Remover TODOS os lançamentos futuros vinculados a ele\n";
                echo "  • Lançamentos passados já realizados serão MANTIDOS\n\n";

                $confirm = strtoupper(UI::ask("Confirma remoção COMPLETA? (S/N)", "N"));
                if ($confirm == 'S') {
                    $stDeact = $this->eng->db->prepare(
                        "UPDATE recurring_templates SET active=0 WHERE id=?"
                    );
                    $stDeact->execute([(int)$idRaw]);

                    // [BUG-R03] Usa o menor período entre o selecionado e o atual real
                    // para garantir que todos os lançamentos futuros sejam removidos,
                    // independente de qual período o usuário está navegando.
                    $realNow    = date('Y-m');
                    $cutoff     = min($this->eng->period, $realNow);
                    $stDel = $this->eng->db->prepare(
                        "DELETE FROM movements WHERE template_id=? AND period >= ?"
                    );
                    $stDel->execute([(int)$idRaw, $cutoff]);

                    // [BUG-R01] Remove supressões do template desativado — irrelevantes agora
                    $this->eng->clearSuppressions((int)$idRaw);

                    $this->eng->needsResync = true;
                    UI::success("Recorrência e lançamentos futuros removidos!");
                    sleep(2);
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // LEDGER — Livro Caixa com saldo running (v2.1) e delete/edit corretos
    // [BUG-03] DELETE e SELECT por ID via prepared statement (sem interpolação)
    // -------------------------------------------------------------------------
    private function ledger() {
        while (true) {
            UI::clear();

            // [BUG-24] Período livre quebrava queries. Valida formato antes de usar.
            $targetPeriod = UI::askUntilValid(
                "Qual período visualizar/editar? (AAAA-MM)",
                fn($v) => UI::isValidPeriod($v),
                "Formato inválido. Use AAAA-MM (ex: 2025-06).",
                $this->eng->period
            );

            UI::line();
            echo " LIVRO CAIXA - " . UI::cyan($targetPeriod) . "\n";
            UI::info("Remover aqui = remove APENAS este lançamento (não afeta outros meses)");
            UI::line();

            $stList = $this->eng->db->prepare(
                "SELECT * FROM movements WHERE period=? ORDER BY date, type DESC"
            );
            $stList->execute([$targetPeriod]);
            $list = $stList->fetchAll(PDO::FETCH_ASSOC);

            if (empty($list)) {
                echo UI::yellow(" Nenhum lançamento encontrado para este período.\n");
            } else {
                UI::tableRow(["ID", "Data", "Descrição", "Categoria", "Valor", "Saldo"], [4, 11, 18, 12, 13, 13]);
                $runningBalance = 0;
                foreach ($list as $l) {
                    $isIncome = strpos($l['type'], 'IN') !== false;
                    $amount   = (float)$l['amount'];
                    if ($isIncome) {
                        $runningBalance += $amount;
                        $valStr = UI::green(UI::currency($amount));
                    } else {
                        $runningBalance -= $amount;
                        $valStr = UI::red(UI::currency($amount));
                    }
                    $balStr = $runningBalance >= 0
                        ? UI::cyan(UI::currency($runningBalance))
                        : UI::red(UI::currency($runningBalance));
                    UI::tableRow([
                        $l['id'],
                        $l['date'],
                        $l['description'],
                        UI::grey($l['category']),
                        $valStr,
                        $balStr,
                    ], [4, 11, 18, 12, 13, 13]);
                }
                UI::line();
                echo " " . UI::bold("Saldo Final: ")
                    . ($runningBalance >= 0
                        ? UI::green(UI::currency($runningBalance))
                        : UI::red(UI::currency($runningBalance))) . "\n";
            }

            UI::line();
            $id = UI::ask("ID para Ação ou [V]oltar", "V");
            if (strtoupper($id) == 'V') break;

            // Valida que o ID é numérico antes de executar a query
            if (!ctype_digit($id) || (int)$id <= 0) {
                UI::error("ID inválido. Digite um número ou V para voltar.");
                sleep(1);
                continue;
            }

            $stCurr = $this->eng->db->prepare("SELECT * FROM movements WHERE id=?");
            $stCurr->execute([(int)$id]);
            $curr = $stCurr->fetch(PDO::FETCH_ASSOC);

            if ($curr) {
                $acao = strtoupper(UI::ask("[E]ditar ou [D]eletar", "E"));
                if ($acao == 'D') {
                    UI::warning("Você vai remover este lançamento APENAS deste mês.");

                    // [BUG-R04] Informa o usuário quando está removendo um lançamento fixo
                    $isFixed = in_array($curr['type'], ['OUT_FIX', 'IN_FIX'])
                               && !empty($curr['template_id']);
                    if ($isFixed) {
                        UI::info("Este é um lançamento recorrente. Será suprimido apenas neste período.");
                        UI::info("O template continua ativo para os demais meses.");
                    }

                    $confirm = strtoupper(UI::ask("Confirma exclusão? (S/N)", "N"));
                    if ($confirm == 'S') {
                        $stDel = $this->eng->db->prepare("DELETE FROM movements WHERE id=?");
                        $stDel->execute([(int)$id]);

                        // [BUG-R01/R04] Se era lançamento fixo (gerado por template),
                        // grava supressão para que sync() não o recrie neste período.
                        if ($isFixed) {
                            $this->eng->suppressSync((int)$curr['template_id'], $curr['period']);
                        }

                        $this->eng->needsResync = true;
                        UI::success("Lançamento excluído permanentemente!");
                        sleep(1);
                    }
                } else {
                    $nd = UI::askUntilValid(
                        "Descrição",
                        fn($v) => trim($v) !== '',
                        "Descrição não pode ser vazia.",
                        $curr['description']
                    );

                    // [BUG-18] Valor inválido (letras, zero) persistia R$0,00 sem aviso.
                    $nv = UI::askUntilValid(
                        "Valor (ex: 1200.00)",
                        fn($v) => UI::isValidAmount($v),
                        "Valor inválido. Use números maiores que zero.",
                        (string)$curr['amount']
                    );

                    // [BUG-19] Data inválida corromperia o campo period do lançamento.
                    $dt = UI::askUntilValid(
                        "Data (AAAA-MM-DD)",
                        fn($v) => UI::isValidDate($v),
                        "Data inválida. Use AAAA-MM-DD com data real (ex: 2025-06-15).",
                        $curr['date']
                    );

                    $nc        = UI::ask("Categoria", $curr['category']);
                    if (trim($nc) === '') $nc = $curr['category'];
                    $newPeriod = substr($dt, 0, 7);

                    $stUpd = $this->eng->db->prepare(
                        "UPDATE movements SET description=?, amount=?, date=?, period=?, category=? WHERE id=?"
                    );
                    $stUpd->execute([trim($nd), (float)$nv, $dt, $newPeriod, trim($nc), (int)$id]);
                    $this->eng->needsResync = true;
                    UI::success("Lançamento atualizado permanentemente!");
                    sleep(1);
                }
            } else {
                UI::error("ID $id não encontrado neste período.");
                sleep(1);
            }
        }
    }

    // -------------------------------------------------------------------------
    // DETAILED PROJECTION — Fluxo anual (v1.0/v2.1)
    // [BUG-03] Queries com $p via prepared statements
    // -------------------------------------------------------------------------
    private function detailedProjection() {
        UI::clear();
        $year = substr($this->eng->period, 0, 4);
        UI::line();
        echo " PROJEÇÃO DE FLUXO DE CAIXA ANUAL - $year\n";
        UI::line();
        UI::tableRow(["Mês", "Receita", "Despesa", "Saldo", "Status"], [8, 16, 16, 16, 12]);
        UI::line();

        $lastRevenue = 0;
        $stIn  = $this->eng->db->prepare("SELECT SUM(amount) FROM movements WHERE period=? AND type LIKE 'IN%'");
        $stOut = $this->eng->db->prepare("SELECT SUM(amount) FROM movements WHERE period=? AND type LIKE 'OUT%'");
        // [BUG-R05] Verifica se mês futuro já foi sincronizado (tem movements reais)
        $stHas = $this->eng->db->prepare("SELECT 1 FROM movements WHERE period=? LIMIT 1");

        for ($i = 1; $i <= 12; $i++) {
            $p      = "$year-" . str_pad($i, 2, '0', STR_PAD_LEFT);
            $in     = 0;
            $out    = 0;
            $status = "";

            if ($p <= $this->eng->period) {
                $stIn->execute([$p]);
                $stOut->execute([$p]);
                $in     = (float)($stIn->fetchColumn()  ?: 0);
                $out    = (float)($stOut->fetchColumn() ?: 0);
                $status = UI::grey("Real");
                $lastRevenue = $in;
            } else {
                // [BUG-R05] Se já tem movements, usa os reais — não projetar via template
                $stHas->execute([$p]);
                if ($stHas->fetch()) {
                    $stIn->execute([$p]);
                    $stOut->execute([$p]);
                    $in     = (float)($stIn->fetchColumn()  ?: 0);
                    $out    = (float)($stOut->fetchColumn() ?: 0);
                    $status = UI::grey("Sync");
                } else {
                    $status = UI::cyan("Proj");
                    $tpls   = $this->eng->db->query(
                        "SELECT id, amount, type, start_date, end_date FROM recurring_templates WHERE active=1"
                    )->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($tpls as $t) {
                        if (!empty($t['start_date']) && $p < $t['start_date']) continue;
                        if (!empty($t['end_date'])   && $p > $t['end_date'])   continue;
                        // [BUG-I] Respeita supressões em meses futuros projetados
                        $chkS = $this->eng->db->prepare(
                            "SELECT 1 FROM sync_suppressions WHERE template_id=? AND period=?"
                        );
                        $chkS->execute([(int)$t['id'], $p]);
                        if ($chkS->fetch()) continue;

                        if ($t['type'] == 'IN') $in  += $t['amount'];
                        else                    $out += $t['amount'];
                    }
                    if ($in == 0 && $lastRevenue > 0) {
                        $in     = $lastRevenue;
                        $status = UI::purple("Simul");
                    }
                }
            }

            $bal = $in - $out;
            UI::tableRow([
                date('M', mktime(0, 0, 0, $i, 10)),
                UI::green(UI::currency($in)),
                UI::red(UI::currency($out)),
                $bal >= 0 ? UI::cyan(UI::currency($bal)) : UI::red(UI::currency($bal)),
                $status,
            ], [8, 16, 16, 16, 12]);
        }
        UI::line();
        UI::ask("Pressione Enter para voltar");
    }

    // -------------------------------------------------------------------------
    // ANNUAL BALANCE (v2.1 — feature nova, preservada)
    // -------------------------------------------------------------------------
    private function annualBalance() {
        UI::clear();
        // [BUG-26] Ano inválido ("abc") era silenciosamente convertido para 0.
        $year = UI::askUntilValid(
            "Ano para análise (AAAA)",
            fn($v) => preg_match('/^\d{4}$/', $v) && (int)$v >= 2000 && (int)$v <= 2100,
            "Ano inválido. Use 4 dígitos (ex: 2025).",
            substr($this->eng->period, 0, 4)
        );
        $report = $this->eng->getAnnualReport($year);

        UI::line();
        echo " " . UI::bold("📈 BALANÇO ANUAL COMPLETO - $year") . "\n";
        UI::line();

        UI::drawSummaryBlock("Resumo Financeiro do Ano", "💼", [
            'Total Receitas' => ['val' => $report['total_income'],  'color' => 'green'],
            'Total Despesas' => ['val' => $report['total_expense'], 'color' => 'red'],
            'Saldo do Ano'   => ['val' => $report['balance'],
                                 'color' => $report['balance'] >= 0 ? 'cyan' : 'red'],
        ]);

        echo " " . UI::orange(UI::bold("📊 Análise por Categoria")) . "\n";
        UI::tableRow(["Categoria", "Tipo", "Qtd", "Total", "Média"], [22, 8, 6, 15, 15]);
        foreach ($report['categories'] as $cat) {
            $typeLabel = $cat['type'] == 'IN' ? UI::green("Receita") : UI::red("Despesa");
            UI::tableRow([
                $cat['category'],
                $typeLabel,
                $cat['count'],
                UI::currency($cat['total']),
                UI::currency($cat['average']),
            ], [22, 8, 6, 15, 15]);
        }
        UI::line();

        echo " " . UI::orange(UI::bold("🔥 Top 10 Maiores Despesas")) . "\n";
        UI::tableRow(["Data", "Descrição", "Categoria", "Valor"], [12, 25, 15, 15]);
        foreach ($report['top_expenses'] as $exp) {
            UI::tableRow([
                $exp['date'],
                $exp['description'],
                UI::grey($exp['category']),
                UI::red(UI::currency($exp['amount'])),
            ], [12, 25, 15, 15]);
        }
        UI::line();

        echo " " . UI::orange(UI::bold("📅 Evolução Mensal")) . "\n";
        UI::tableRow(["Período", "Receitas", "Despesas", "Saldo"], [10, 16, 16, 16]);
        foreach ($report['monthly'] as $m) {
            $balance = $m['income'] - $m['expense'];
            UI::tableRow([
                $m['period'],
                UI::green(UI::currency($m['income'])),
                UI::red(UI::currency($m['expense'])),
                $balance >= 0 ? UI::cyan(UI::currency($balance)) : UI::red(UI::currency($balance)),
            ], [10, 16, 16, 16]);
        }
        UI::line();

        UI::ask("Pressione Enter para voltar");
    }

    // -------------------------------------------------------------------------
    // MONTHLY ANALYSIS (v2.1 — feature nova, preservada)
    // [BUG-03] Queries com $period via prepared statement
    // -------------------------------------------------------------------------
    private function monthlyAnalysis() {
        UI::clear();
        // [BUG-24][BUG-25] Período inválido causava strtotime(false) → date() = "1969-12".
        $period = UI::askUntilValid(
            "Qual período analisar? (AAAA-MM)",
            fn($v) => UI::isValidPeriod($v),
            "Formato inválido. Use AAAA-MM (ex: 2025-06).",
            $this->eng->period
        );

        UI::line();
        echo " " . UI::bold("📊 ANÁLISE DETALHADA - " . UI::cyan($period)) . "\n";
        UI::line();

        $stIn  = $this->eng->db->prepare("SELECT SUM(amount) FROM movements WHERE period=? AND type LIKE 'IN%'");
        $stOut = $this->eng->db->prepare("SELECT SUM(amount) FROM movements WHERE period=? AND type LIKE 'OUT%'");
        $stIn->execute([$period]);
        $stOut->execute([$period]);
        $income  = (float)($stIn->fetchColumn()  ?: 0);
        $expense = (float)($stOut->fetchColumn() ?: 0);
        $balance = $income - $expense;

        UI::drawSummaryBlock("Resumo do Período", "💰", [
            'Receitas' => ['val' => $income,  'color' => 'green'],
            'Despesas' => ['val' => $expense, 'color' => 'red'],
            'Saldo'    => ['val' => $balance, 'color' => $balance >= 0 ? 'cyan' : 'red'],
        ]);

        echo " " . UI::orange(UI::bold("Por Categoria")) . "\n";
        $catStmt = $this->eng->db->prepare("
            SELECT category, SUM(amount) AS total, COUNT(*) AS count
            FROM movements
            WHERE period=? AND type LIKE 'OUT%'
            GROUP BY category
            ORDER BY total DESC
        ");
        $catStmt->execute([$period]);
        $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($categories)) {
            UI::tableRow(["Categoria", "Qtd", "Total", "% Gasto"], [25, 8, 15, 12]);
            foreach ($categories as $cat) {
                $pct = $expense > 0 ? ($cat['total'] / $expense) * 100 : 0;
                UI::tableRow([
                    $cat['category'],
                    $cat['count'],
                    UI::currency($cat['total']),
                    UI::percent($pct),
                ], [25, 8, 15, 12]);
            }
        } else {
            echo UI::yellow(" Nenhuma despesa neste período.\n");
        }
        UI::line();

        echo " " . UI::orange(UI::bold("Top 5 Maiores Despesas")) . "\n";
        $topStmt = $this->eng->db->prepare("
            SELECT description, amount, date, category
            FROM movements
            WHERE period=? AND type LIKE 'OUT%'
            ORDER BY amount DESC
            LIMIT 5
        ");
        $topStmt->execute([$period]);
        $top = $topStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($top)) {
            UI::tableRow(["Data", "Descrição", "Categoria", "Valor"], [12, 22, 15, 15]);
            foreach ($top as $t) {
                UI::tableRow([
                    $t['date'],
                    $t['description'],
                    UI::grey($t['category']),
                    UI::red(UI::currency($t['amount'])),
                ], [12, 22, 15, 15]);
            }
        }
        UI::line();

        // Comparativo com mês anterior
        $prevPeriod = date('Y-m', strtotime($period . "-01 -1 month"));
        $stIn->execute([$prevPeriod]);
        $stOut->execute([$prevPeriod]);
        $prevIncome  = (float)($stIn->fetchColumn()  ?: 0);
        $prevExpense = (float)($stOut->fetchColumn() ?: 0);

        echo " " . UI::orange(UI::bold("Comparação com $prevPeriod")) . "\n";
        $incomeChange  = $prevIncome  > 0 ? (($income  - $prevIncome)  / $prevIncome)  * 100 : 0;
        $expenseChange = $prevExpense > 0 ? (($expense - $prevExpense) / $prevExpense) * 100 : 0;

        $incomeChangeStr  = $incomeChange  >= 0
            ? UI::green("+" . number_format($incomeChange, 1) . '%')
            : UI::red(number_format($incomeChange, 1) . '%');
        $expenseChangeStr = $expenseChange >= 0
            ? UI::red("+" . number_format($expenseChange, 1) . '%')
            : UI::green(number_format($expenseChange, 1) . '%');

        echo "  Receitas: " . UI::currency($prevIncome)  . " → " . UI::currency($income)  . " ($incomeChangeStr)\n";
        echo "  Despesas: " . UI::currency($prevExpense) . " → " . UI::currency($expense) . " ($expenseChangeStr)\n";

        UI::line();
        UI::ask("Pressione Enter para voltar");
    }
}

(new FinApp())->run();
