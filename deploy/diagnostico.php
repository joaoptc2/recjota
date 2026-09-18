<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Diagnóstico de instalação — arquivo temporário
|------------------------------------------------------------------------------
| Para usar quando o site devolve 403, 500 ou página em branco e não há SSH
| para investigar.
|
|   1. Envie ESTE arquivo para a pasta que o seu domínio publica
|      (na Hostinger costuma se chamar public_html, mas o nome não importa:
|       é a pasta onde fica o index.php do site)
|   2. Abra https://seudominio.com.br/diagnostico.php
|   3. Siga a instrução de chave que aparecer
|   4. APAGUE este arquivo e o diagnostico.chave.txt ao terminar
|
| Não depende do Laravel e não lê o conteúdo do .env. Mesmo assim o relatório
| mostra caminhos do servidor e o fim do log de erros, então ele fica atrás de
| uma chave: quem não consegue abrir um arquivo na pasta não vê nada.
*/

$aqui = __DIR__;
$arquivoChave = $aqui.'/diagnostico.chave.txt';

// Alternativa para quando a pasta não é gravável: preencha entre as aspas.
$chaveManual = '';

// ------------------------------------------------------------------ portaria

$chaveEsperada = $chaveManual !== '' ? $chaveManual : (is_file($arquivoChave) ? trim((string) file_get_contents($arquivoChave)) : '');
$podeGravar = is_writable($aqui);

if ($chaveEsperada === '' && $podeGravar) {
    try {
        $chaveEsperada = bin2hex(random_bytes(8));
        file_put_contents($arquivoChave, $chaveEsperada."\n", LOCK_EX);
    } catch (Throwable) {
        $chaveEsperada = '';
    }
}

$chaveInformada = (string) ($_GET['chave'] ?? '');
$liberado = $chaveEsperada !== '' && hash_equals($chaveEsperada, $chaveInformada);

if (! $liberado) {
    http_response_code(403);
    ?><!DOCTYPE html>
    <html lang="pt-BR"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow"><title>Diagnóstico</title>
    <style>
        body{margin:0;padding:40px 16px;background:#f8fafc;color:#0f172a;
             font:15px/1.6 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
        @media(prefers-color-scheme:dark){body{background:#020617;color:#e2e8f0}}
        .w{max-width:560px;margin:0 auto}
        h1{font-size:1.2rem;margin:0 0 12px}
        code{background:rgba(127,127,127,.18);padding:2px 6px;border-radius:4px;font-size:13px}
        ol{padding-left:20px}
    </style></head><body><div class="w">
        <h1>Diagnóstico protegido por chave</h1>
        <?php if ($chaveEsperada === '') { ?>
            <p>Não consegui criar o arquivo de chave nesta pasta, então preciso que você defina uma
            manualmente:</p>
            <ol>
                <li>Abra <code>diagnostico.php</code> no Gerenciador de Arquivos.</li>
                <li>Na linha <code>$chaveManual = '';</code>, escreva algo entre as aspas.</li>
                <li>Acesse esta página com <code>?chave=</code> seguido do que você escreveu.</li>
            </ol>
        <?php } else { ?>
            <p>Gerei uma chave e gravei ao lado deste arquivo. Para ver o relatório:</p>
            <ol>
                <li>Abra <code>diagnostico.chave.txt</code> no Gerenciador de Arquivos
                    (mesma pasta deste arquivo).</li>
                <li>Copie o conteúdo e acesse
                    <code>?chave=<em>o-que-estiver-lá</em></code>.</li>
            </ol>
            <p>Isso existe porque o relatório mostra caminhos do servidor e trechos do log — coisas que
            não devem ficar abertas na internet.</p>
        <?php } ?>
    </div></body></html>
    <?php
    exit;
}

// ---------------------------------------------------------------- utilidades

$nomeDaPasta = basename($aqui);
$app = dirname($aqui).'/app';

/** Permissão em octal, legível. */
$perm = static function (string $caminho): string {
    return file_exists($caminho) ? substr(sprintf('%o', fileperms($caminho)), -3) : '—';
};

$linha = static function (string $item, bool $ok, string $detalhe, string $comoResolver = ''): array {
    return compact('item', 'ok', 'detalhe', 'comoResolver');
};

// ---------------------------------------------------------------- estrutura

$indexAqui = $aqui.'/index.php';
$htaccessAqui = $aqui.'/.htaccess';

$estrutura = [
    $linha(
        'index.php nesta pasta',
        is_file($indexAqui),
        is_file($indexAqui)
            ? 'Encontrado, '.number_format((float) filesize($indexAqui)).' bytes, permissão '.$perm($indexAqui)
            : 'AUSENTE — é a causa nº 1 de 403 nesta configuração',
        'O CONTEÚDO da pasta de document root do pacote vai solto aqui dentro, não como uma subpasta.',
    ),
    $linha(
        '.htaccess nesta pasta',
        is_file($htaccessAqui),
        is_file($htaccessAqui) ? 'Encontrado, permissão '.$perm($htaccessAqui) : 'Ausente — sem ele as rotas não funcionam',
        'Muitos clientes de FTP escondem arquivos que começam com ponto. Ligue a opção de mostrar ocultos e reenvie.',
    ),
    $linha(
        'pasta app/ um nível acima',
        is_dir($app),
        is_dir($app) ? $app : 'AUSENTE em '.$app,
        'A pasta "app" do pacote fica FORA desta pasta, ao lado dela.',
    ),
    $linha(
        'app/vendor/autoload.php',
        is_file($app.'/vendor/autoload.php'),
        is_file($app.'/vendor/autoload.php') ? 'Encontrado' : 'AUSENTE — o envio das bibliotecas ficou incompleto',
        'Reenvie a pasta app/vendor inteira. São milhares de arquivos; o FTP costuma parar no meio.',
    ),
    $linha(
        'app/.env',
        is_file($app.'/.env'),
        is_file($app.'/.env') ? 'Encontrado, permissão '.$perm($app.'/.env') : 'AUSENTE',
        'Vem pronto no pacote, mas começa com ponto — ligue a exibição de arquivos ocultos no FTP.',
    ),
    $linha(
        'build/ (CSS e JavaScript)',
        is_file($aqui.'/build/manifest.json'),
        is_file($aqui.'/build/manifest.json') ? 'Encontrado' : 'Ausente — as telas carregam sem estilo',
        'Copie a pasta build do document root do pacote.',
    ),
];

/*
 * Erro clássico: baixar o repositório em "Code › Download ZIP" e descompactar
 * aqui. Vem o código-fonte inteiro, sem vendor/ — e ainda por cima dentro do
 * document root, com config/, database/ e storage/ expostos na web.
 */
$marcasDeFonte = array_filter([
    'phpunit.xml' => is_file($aqui.'/phpunit.xml'),
    'tests/' => is_dir($aqui.'/tests'),
    'package.json' => is_file($aqui.'/package.json'),
    'vite.config.js' => is_file($aqui.'/vite.config.js'),
    'composer.json' => is_file($aqui.'/composer.json'),
    'artisan' => is_file($aqui.'/artisan'),
]);

$enviouOFonte = count($marcasDeFonte) >= 3 && ! is_dir($aqui.'/vendor');

// Pastas que vieram no lugar errado são a pista mais útil de todas.
$suspeitas = [];
foreach (['public_html', 'app', 'recjota', 'document-root'] as $nome) {
    if (is_dir($aqui.'/'.$nome)) {
        $suspeitas[] = $nome;
    }
}

// ------------------------------------------------------------- permissões

$graváveis = [
    'app/storage' => $app.'/storage',
    'app/storage/framework' => $app.'/storage/framework',
    'app/storage/framework/views' => $app.'/storage/framework/views',
    'app/storage/framework/sessions' => $app.'/storage/framework/sessions',
    'app/storage/framework/cache' => $app.'/storage/framework/cache',
    'app/storage/logs' => $app.'/storage/logs',
    'app/bootstrap/cache' => $app.'/bootstrap/cache',
];

$permissoes = [];
foreach ($graváveis as $rótulo => $caminho) {
    $existe = is_dir($caminho);
    $permissoes[] = $linha(
        $rótulo,
        $existe && is_writable($caminho),
        $existe ? 'Permissão '.$perm($caminho).($existe && is_writable($caminho) ? ', gravável' : ', SEM escrita') : 'Pasta ausente',
        'Gerenciador de Arquivos › botão direito na pasta › Permissões › 755, marcando "aplicar às subpastas".',
    );
}

$permissoes[] = $linha(
    $nomeDaPasta.'/ (leitura)',
    is_readable($aqui),
    'Permissão '.$perm($aqui),
    'A pasta precisa ser 755. Com 700 o servidor web não consegue entrar nela e devolve 403.',
);

if (is_file($indexAqui)) {
    $permissoes[] = $linha(
        $nomeDaPasta.'/index.php (leitura)',
        is_readable($indexAqui),
        'Permissão '.$perm($indexAqui),
        'O arquivo precisa ser 644. Com 600 o servidor devolve 403.',
    );
}

/*
 * Travessia de diretórios.
 *
 * "403 Forbidden — Access to this resource on the server is denied!" é a
 * resposta clássica quando o servidor web não consegue ENTRAR em alguma pasta
 * do caminho — e não precisa ser a pasta publicada: basta uma pasta-pai com
 * permissão 700. O erro não diz qual é, então listamos todas.
 */
$travessia = [];
$acumulado = '';
foreach (array_filter(explode('/', $aqui)) as $parte) {
    $acumulado .= '/'.$parte;
    $modo = $perm($acumulado);
    // O bit x do "outros" é o que permite atravessar a pasta.
    $atravessavel = $modo !== '—' && ((int) substr($modo, -1)) % 2 === 1;

    $travessia[] = $linha(
        $acumulado,
        $atravessavel,
        'Permissão '.$modo,
        'Gerenciador de Arquivos › botão direito nesta pasta › Permissões › 755. '
        .'Sem o bit de execução, o servidor não consegue atravessá-la e responde 403.',
    );
}

// --------------------------------------------------------------------- PHP

$extensões = ['pdo_mysql', 'curl', 'mbstring', 'openssl', 'gd', 'zip', 'fileinfo', 'intl'];
$php = [
    $linha('Versão do PHP', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION, 'hPanel › Avançado › Configuração PHP'),
];
foreach ($extensões as $ext) {
    $php[] = $linha('Extensão '.$ext, extension_loaded($ext), extension_loaded($ext) ? 'Carregada' : 'AUSENTE', 'hPanel › Avançado › Configuração PHP › Extensões PHP');
}

// ------------------------------------------------------- onde está o envio

/**
 * Quando os arquivos não estão onde deveriam, saber ONDE eles foram parar vale
 * mais do que saber que faltam. Varredura limitada em profundidade e em número
 * de pastas, para não travar numa conta grande.
 */
$procurar = static function (array $raizes, int $profundidadeMax = 4, int $orcamento = 4000): array {
    $achados = [];
    $vistos = 0;
    $ignorar = ['vendor', 'node_modules', '.git', '.cache', '.trash', 'cache', 'logs', '.wp-cli'];

    foreach ($raizes as $raiz) {
        if (! is_dir($raiz) || ! is_readable($raiz)) {
            continue;
        }

        $fila = [[$raiz, 0]];

        while ($fila !== [] && $vistos < $orcamento) {
            [$dir, $nivel] = array_shift($fila);
            $vistos++;

            // Marcadores que identificam o pacote sem ambiguidade.
            if (is_file($dir.'/artisan') && is_dir($dir.'/bootstrap')) {
                $achados['aplicação (pasta app)'] ??= $dir;
            }
            if (is_file($dir.'/vendor/autoload.php')) {
                $achados['bibliotecas (vendor)'] ??= $dir.'/vendor';
            }
            if (is_file($dir.'/index.php') && is_dir($dir.'/build')) {
                $achados['document root do pacote'] ??= $dir;
            }
            if (is_file($dir.'/.env') && is_file($dir.'/artisan')) {
                $achados['.env'] ??= $dir.'/.env';
            }

            foreach ((array) @glob($dir.'/*.zip') as $zip) {
                $achados['pacote .zip ainda compactado'] ??= $zip;
            }

            if ($nivel >= $profundidadeMax) {
                continue;
            }

            foreach ((array) @scandir($dir) as $filho) {
                if ($filho === '.' || $filho === '..' || in_array($filho, $ignorar, true)) {
                    continue;
                }

                $caminho = $dir.'/'.$filho;

                if (is_dir($caminho) && ! is_link($caminho) && is_readable($caminho)) {
                    $fila[] = [$caminho, $nivel + 1];
                }
            }
        }
    }

    return $achados;
};

$raizDominio = dirname($aqui);
$casa = dirname(dirname($raizDominio));

$faltaTudo = ! is_file($indexAqui) && ! is_dir($app);
$achados = $faltaTudo ? $procurar([$raizDominio, $casa]) : [];

$conteudoRaiz = [];
foreach ((array) @scandir($raizDominio) as $i) {
    if ($i !== '.' && $i !== '..') {
        $conteudoRaiz[] = [$i, is_dir($raizDominio.'/'.$i), $perm($raizDominio.'/'.$i)];
    }
}

// ------------------------------------------------------------------ último erro

$log = $app.'/storage/logs/laravel.log';
$ultimoErro = null;
if (is_file($log) && is_readable($log)) {
    $conteudo = (string) file_get_contents($log, false, null, max(0, filesize($log) - 4000));
    $ultimoErro = trim(substr($conteudo, -2000));
}

$tudoOk = true;
foreach ([...$estrutura, ...$permissoes, ...$travessia, ...$php] as $c) {
    $tudoOk = $tudoOk && $c['ok'];
}

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Diagnóstico da instalação</title>
<style>
    *,*::before,*::after{box-sizing:border-box}
    :root{color-scheme:light dark;--bg:#f8fafc;--fg:#0f172a;--muted:#64748b;--card:#fff;--line:#e2e8f0;--ok:#047857;--bad:#be123c;--warn:#b45309}
    @media(prefers-color-scheme:dark){:root{--bg:#020617;--fg:#e2e8f0;--muted:#94a3b8;--card:#0f172a;--line:#1e293b;--ok:#34d399;--bad:#fb7185;--warn:#fbbf24}}
    body{margin:0;padding:24px 16px 64px;background:var(--bg);color:var(--fg);font:15px/1.55 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
    .wrap{max-width:760px;margin:0 auto}
    h1{font-size:1.35rem;margin:0 0 4px}
    h2{font-size:1rem;margin:0 0 10px}
    p.lead{color:var(--muted);margin:0 0 20px}
    .card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:20px;margin-bottom:16px}
    ul{list-style:none;margin:0;padding:0}
    li{display:flex;gap:10px;padding:9px 0;border-bottom:1px solid var(--line);align-items:flex-start}
    li:last-child{border-bottom:0}
    .mark{font-weight:700;flex:none;width:1.2em}
    .ok .mark{color:var(--ok)}.bad .mark{color:var(--bad)}
    .item{font-weight:600;font-size:14px}
    .detail{color:var(--muted);font-size:12.5px}
    .fix{color:var(--warn);font-size:12.5px;margin-top:2px}
    .alert{border:1px solid;border-radius:8px;padding:12px 14px;font-size:14px;margin-bottom:16px}
    .alert.ok{border-color:var(--ok);color:var(--ok)}
    .alert.bad{border-color:var(--bad);color:var(--bad)}
    pre{background:var(--bg);border:1px solid var(--line);border-radius:8px;padding:12px;overflow-x:auto;font-size:12px;white-space:pre-wrap;word-break:break-word}
    code{font-size:12.5px}
</style>
</head>
<body>
<div class="wrap">
    <h1>Diagnóstico da instalação</h1>
    <p class="lead">Arquivo temporário. <strong>Apague <code>diagnostico.php</code> e
    <code>diagnostico.chave.txt</code> ao terminar.</strong></p>

    <?php if ($tudoOk) { ?>
        <div class="alert ok">Estrutura e permissões corretas. Acesse a raiz do site: o instalador deve aparecer.</div>
    <?php } else { ?>
        <div class="alert bad">Encontrei problemas. Os itens marcados com ✕ abaixo dizem o que fazer.</div>
    <?php } ?>

    <?php if ($enviouOFonte) { ?>
        <div class="alert bad">
            <strong>Isto aqui é o código-fonte, não o pacote de instalação.</strong>
            Encontrei <?= htmlspecialchars(implode(', ', array_keys($marcasDeFonte))) ?> e nenhuma pasta
            <code>vendor</code> — é o que vem quando se usa "Code › Download ZIP" no GitHub.
            O sistema não roda assim: faltam as bibliotecas, que só o pacote de instalação traz prontas.
            <br><br>
            Pior: com o projeto dentro do document root, <code>config/</code>, <code>database/</code> e
            <code>storage/</code> ficam acessíveis pela internet. <strong>Apague tudo o que está nesta
            pasta</strong> e envie o pacote de instalação no lugar — ele tem duas pastas, e só uma delas
            vai aqui dentro.
        </div>
    <?php } ?>

    <?php if ($suspeitas !== []) { ?>
        <div class="alert bad">
            Encontrei <strong><?= implode(', ', array_map('htmlspecialchars', $suspeitas)) ?></strong> dentro
            desta pasta. Isso indica que o pacote foi descompactado um nível fundo demais: o
            <code>index.php</code> precisa ficar solto aqui, e a pasta <code>app</code> precisa ficar um
            nível ACIMA, fora desta.
        </div>
    <?php } ?>

    <?php if ($faltaTudo) { ?>
        <div class="card">
            <h2>Onde estão os arquivos do pacote</h2>
            <?php if ($achados === []) { ?>
                <p class="detail" style="margin:0 0 10px">
                    Procurei em <code><?= htmlspecialchars($raizDominio) ?></code> e em
                    <code><?= htmlspecialchars($casa) ?></code> e não encontrei nenhum vestígio do
                    pacote — nem <code>artisan</code>, nem <code>vendor</code>, nem <code>.zip</code>.
                </p>
                <p class="detail" style="margin:0">
                    Ou o envio não chegou ao servidor, ou foi para uma conta/domínio diferente deste.
                </p>
            <?php } else { ?>
                <p class="detail" style="margin:0 0 10px">Encontrei estes pedaços do pacote:</p>
                <ul>
                    <?php foreach ($achados as $oQue => $onde) { ?>
                        <li class="bad">
                            <span class="mark">→</span>
                            <span>
                                <span class="item"><?= htmlspecialchars($oQue) ?></span>
                                <div class="detail"><code><?= htmlspecialchars($onde) ?></code></div>
                            </span>
                        </li>
                    <?php } ?>
                </ul>
                <p class="fix" style="margin-top:12px">
                    Mova a pasta da aplicação para <code><?= htmlspecialchars($app) ?></code> e o conteúdo
                    do document root para <code><?= htmlspecialchars($aqui) ?></code>.
                </p>
            <?php } ?>
        </div>

        <div class="card">
            <h2>Conteúdo de <?= htmlspecialchars($raizDominio) ?></h2>
            <pre><?php
                foreach ($conteudoRaiz as [$nome, $ehPasta, $p]) {
                    printf("%-30s %s  %s\n", htmlspecialchars($nome), $ehPasta ? '[pasta]  ' : '[arquivo]', $p);
                }
                if ($conteudoRaiz === []) {
                    echo '(vazio ou sem permissão de leitura)';
                }
            ?></pre>
            <p class="detail" style="margin:0">
                É aqui que a pasta <code>app</code> precisa aparecer, ao lado de
                <code><?= htmlspecialchars($nomeDaPasta) ?></code>.
            </p>
        </div>
    <?php } ?>

    <div class="card">
        <h2>Qual pasta o seu domínio publica</h2>
        <pre><?= htmlspecialchars($aqui) ?></pre>
        <p class="detail" style="margin:0">
            É esta — o nome dela é <code><?= htmlspecialchars($nomeDaPasta) ?></code>. A pasta
            <code>app</code> deve ficar em <code><?= htmlspecialchars($app) ?></code>.
        </p>
    </div>

    <?php
    $blocos = [
        'Estrutura dos arquivos' => $estrutura,
        'Permissões' => $permissoes,
        'Travessia até esta pasta' => $travessia,
        'Ambiente PHP' => $php,
    ];
    foreach ($blocos as $titulo => $checks) { ?>
        <div class="card">
            <h2><?= htmlspecialchars($titulo) ?></h2>
            <ul>
                <?php foreach ($checks as $c) { ?>
                    <li class="<?= $c['ok'] ? 'ok' : 'bad' ?>">
                        <span class="mark"><?= $c['ok'] ? '✓' : '✕' ?></span>
                        <span>
                            <span class="item"><?= htmlspecialchars($c['item']) ?></span>
                            <span class="detail"> — <?= htmlspecialchars($c['detalhe']) ?></span>
                            <?php if (! $c['ok'] && $c['comoResolver'] !== '') { ?>
                                <div class="fix">Como resolver: <?= htmlspecialchars($c['comoResolver']) ?></div>
                            <?php } ?>
                        </span>
                    </li>
                <?php } ?>
            </ul>
        </div>
    <?php } ?>

    <div class="card">
        <h2>Conteúdo desta pasta</h2>
        <pre><?php
            foreach ((array) @scandir($aqui) as $i) {
                if ($i === '.' || $i === '..') {
                    continue;
                }
                printf("%-28s %s  %s\n", htmlspecialchars($i), is_dir($aqui.'/'.$i) ? '[pasta]  ' : '[arquivo]', $perm($aqui.'/'.$i));
            }
        ?></pre>
    </div>

    <?php if ($ultimoErro) { ?>
        <div class="card">
            <h2>Fim do log de erros</h2>
            <pre><?= htmlspecialchars($ultimoErro) ?></pre>
        </div>
    <?php } ?>

    <p class="detail">Terminou? Apague <code>diagnostico.php</code> e <code>diagnostico.chave.txt</code>.</p>
</div>
</body>
</html>
