# Instalação na Hostinger (sem SSH, sem Composer, sem Node no servidor)

O servidor não roda Composer, não roda Node e não precisa de linha de comando.
Tudo o que ele recebe é um pacote de arquivos; o resto acontece pelo navegador.

Fluxo completo: **baixar o pacote → enviar por FTP → abrir o site → cadastrar o
cron.** Leva uns 20 minutos na primeira vez.

---

## 1. Obter o pacote de instalação

O pacote já vem com as bibliotecas instaladas (`vendor/`), o CSS e o JavaScript
compilados (`build/`) e uma chave de criptografia gerada. Cerca de 20 MB.

> **Não use o botão "Code › Download ZIP" da página do repositório.** Ele
> entrega o código-fonte sem as bibliotecas (`vendor/`), e o sistema não roda
> assim. O pacote de instalação é outro arquivo.

Na página **Releases** do repositório, release **Pacote de instalação (mais
recente)**, seção **Assets**, há dois arquivos. Escolha um:

| Pacote | Como instala | Quando usar |
|---|---|---|
| `...-pasta-unica.zip` | extrai **tudo** dentro da pasta publicada | mais simples; funciona como um site PHP comum |
| `...-duas-pastas.zip` | código fora da pasta publicada | mais seguro; é o recomendado |

**Se esta é a sua primeira instalação, use o de pasta única.** Ele elimina o
erro mais comum — pasta no lugar errado — e o `.htaccess` que acompanha bloqueia
o acesso pela web ao `.env`, ao `vendor`, ao `storage` e às demais pastas
internas. Dá para migrar para o layout de duas pastas depois, movendo arquivos.

O que muda na prática: no modo pasta única, quem proteje os arquivos internos é
o `.htaccess`. No modo duas pastas, eles simplesmente não estão em lugar
algum que o servidor web alcance. A segunda garantia é mais forte porque não
depende de configuração.

Os dois pacotes são remontados a cada alteração no código.

**Opção B — na sua máquina** (precisa de PHP, Composer e Node — só aí, nunca no
servidor)

```bash
./deploy/build.sh
# gera dist/recjota-AAAAMMDD-HHMM.zip
```

> **Guarde uma cópia do `.env` que vem no pacote.** A `APP_KEY` dentro dele
> criptografa os tokens das contas conectadas. Trocar essa chave depois torna
> todos eles ilegíveis, e as contas precisam ser reconectadas uma a uma.

---

## 2. Conferir o plano no hPanel

- [ ] **PHP 8.2 ou superior** — hPanel › Avançado › Configuração PHP.
- [ ] **Extensões**: `pdo_mysql`, `curl`, `mbstring`, `openssl`, `gd`, `zip`,
      `fileinfo`, `intl`. Todas vêm ativas por padrão; a tela de instalação
      confere e avisa se faltar alguma.
- [ ] **SSL ativo** no domínio.
- [ ] **Pelo menos 1 cron job** disponível. O sistema foi desenhado para
      funcionar com um só (R4).

Não é preciso conferir versão de Composer nem de Node: eles não são usados aqui.

---

## 3. Criar o banco de dados

hPanel › **Bancos de Dados MySQL** › criar banco e usuário.

Anote os **nomes completos, com o prefixo** `u........._`. É o erro mais comum:
a Hostinger mostra o nome curto no formulário e usa o longo na conexão.

---

## 4. Descobrir qual pasta o seu domínio publica

hPanel › **Sites** › **Gerenciador de Arquivos**. A pasta que abre por padrão
para o seu domínio é o **document root** — é ali que o site vive.

Na Hostinger ela quase sempre se chama `public_html`, dentro de
`domains/seudominio.com.br/`. Mas o nome varia conforme o plano e o tipo de
domínio, e **o sistema não depende desse nome**. Se a pasta não existir, crie-a
(use `public_html`) e aponte o domínio para ela em hPanel › Sites.

Anote o caminho completo. Nos exemplos abaixo ele aparece como
`/home/uXXXXXXXX/domains/seudominio.com.br/public_html`.

## 5. Enviar os arquivos

Descompacte o `.zip` na sua máquina. Dentro dele há **duas pastas, e elas vão
para lugares diferentes** — cada uma traz um `LEIA-ME.txt` repetindo isto:

| Pasta no pacote | Para onde vai |
|---|---|
| `app/` | **ao lado** do document root, nunca dentro |
| **conteúdo** de `document-root/` | **dentro** do document root |

Note que é o *conteúdo* de `document-root/`, não a pasta. Depois do envio, o
`index.php` precisa estar solto no document root.

```
/home/uXXXXXXXX/domains/seudominio.com.br/
├── app/               ← a pasta "app" do pacote, inteira
└── public_html/       ← o CONTEÚDO de "document-root"
    ├── index.php
    ├── .htaccess
    ├── .user.ini
    ├── build/
    └── media-tmp/
```

Envie pelo **Gerenciador de Arquivos** do hPanel (mais simples: dá para subir o
`.zip` e descompactar lá dentro) ou por qualquer cliente **FTP**.

Dois detalhes que costumam morder:

- **Arquivos ocultos.** `.htaccess`, `.user.ini` e `.env` começam com ponto e
  muitos clientes de FTP não os enviam. Ligue a opção de mostrar arquivos
  ocultos antes de começar.
- **`index.html` antigo.** Se já existir um `index.html` ou `default.php` no
  document root, apague: ele tem prioridade sobre o `index.php` do sistema.

### Por que duas pastas

O document root fica exposto na internet. O código, o `.env`, o `storage/` e o
`vendor/` ficam em `app/`, um nível acima, fora do alcance de qualquer visitante
(R9). Se a aplicação não estiver exatamente um nível acima, o `index.php`
procura nas pastas vizinhas antes de desistir — e, ao desistir, mostra uma
página explicando, em vez de um erro fatal.

---

## 6. Abrir o site

Acesse `https://seudominio.com.br`. O sistema detecta que ainda não foi
instalado e leva direto ao instalador, em quatro telas:

1. **Ambiente** — confere PHP, extensões e permissões. Cada item reprovado diz
   exatamente onde resolver no hPanel.
2. **Configuração** — nome da agência, endereço, fuso de exibição, dados do
   banco e, opcionalmente, do e-mail. A conexão com o banco é testada antes de
   gravar; se falhar, a mensagem diz qual é o problema (senha errada, banco
   inexistente, host inacessível).
3. **Banco** — cria as tabelas e a matriz de papéis. Há uma caixa opcional de
   dados de demonstração, para conhecer o sistema antes de usar pra valer.
   **Não marque numa instalação real.**
4. **Administrador** — cria o usuário proprietário e faz login.

Ao terminar, o instalador **deixa de existir**: `/instalar` passa a devolver
404. Para reabri-lo seria preciso apagar `app/storage/app/installed.lock` **e**
esvaziar a tabela de usuários — as duas coisas, não uma.

### Se a tela de permissões reprovar alguma pasta

Gerenciador de Arquivos › botão direito na pasta › **Permissões** › `755`
(ou `775` se o `755` não bastar). As pastas que precisam de escrita são
`app/storage/` (e tudo dentro dela) e `app/bootstrap/cache/`.

---

## 7. Cadastrar o cron — passo obrigatório

**Sem o cron nada é publicado, nenhum e-mail sai e nenhum token é renovado.**

hPanel › Avançado › **Tarefas Cron** › criar, **a cada minuto**:

**Se o hPanel oferecer o tipo "PHP"** (mais simples):

```
Tipo:    PHP
Arquivo: /home/uXXXXXXXX/domains/seudominio.com.br/app/cron.php
```

**Se oferecer o tipo "Custom"/"Comando"**:

```
* * * * * /bin/bash /home/uXXXXXXXX/domains/seudominio.com.br/app/cron.sh
```

Os dois fazem a mesma coisa. O `cron.php` existe porque o tipo "PHP" do hPanel
não aceita os caracteres `>` e `&` de uma linha de shell (R3).

Se o plano limitar a frequência, `*/5 * * * *` também funciona — as janelas de
publicação só ficam menos precisas.

> O cron do hPanel roda em **UTC**. É exatamente por isso que o banco inteiro é
> UTC e a conversão para o horário de Brasília acontece só na tela.

### Conferir se o cron pegou

Espere uns 20 minutos e abra `https://seudominio.com.br/health`. O campo
`checks.cron_heartbeat.ok` precisa ser `true`. Enquanto o cron não rodar, o
endpoint responde **503** — de propósito.

O console de manutenção (próxima seção) também mostra isso em português, logo
no topo.

---

## 8. Console de manutenção — o terminal que a hospedagem não tem

`https://seudominio.com.br/manutencao`, acessível para quem tem papel
**proprietário**.

Botões disponíveis, cada um com explicação na tela:

| Botão | Quando usar |
|---|---|
| Atualizar o banco | Depois de enviar uma nova versão do sistema |
| Sincronizar papéis e permissões | Depois de atualizações que mexam em permissões |
| Limpar os caches | Sempre que alterar o `.env` pelo Gerenciador de Arquivos |
| Reconstruir os caches | Depois de limpar, para o sistema voltar a ficar rápido |
| Processar a fila agora | Para conferir se e-mails e publicações estão saindo |
| Registrar batimento / Rodar o agendador | Para testar o agendador sem esperar o cron |

Ele **não aceita comando digitado**: só existe o que está nessa lista, com
argumentos fixos no código.

### Porta de emergência

Se uma atualização quebrar o login, preencha `MAINTENANCE_TOKEN` no `.env` com
64 caracteres aleatórios e acesse
`https://seudominio.com.br/manutencao?token=SEU_TOKEN`.

**Apague o token do `.env` assim que terminar.** Vazio (o padrão de fábrica), o
console só abre para o proprietário autenticado.

---

## 9. Atualizar o sistema depois

1. Gere um pacote novo (seção 1).
2. Envie por FTP **apenas a pasta `app/`** e a pasta `build/` do document root,
   sobrescrevendo. **Não sobrescreva o `.env`** — ele tem as suas credenciais e
   a sua `APP_KEY`.
3. Abra `/manutencao` e clique, nesta ordem: **Atualizar o banco** → **Limpar os
   caches** → **Reconstruir os caches**.

---

## 10. Problemas comuns

### Ferramenta de diagnóstico

Quando o site não abre e não há SSH para investigar, o pacote traz
`diagnostico.php` na raiz. Copie o arquivo para dentro do document root e
acesse `https://seudominio.com.br/diagnostico.php`.

Na primeira visita ele gera uma chave e a grava em `diagnostico.chave.txt`, ao
lado dele. Abra esse arquivo pelo Gerenciador de Arquivos e acesse
`?chave=<o-que-estiver-lá>`. Essa volta existe porque o relatório mostra
caminhos do servidor e trechos do log, e nada disso pode ficar aberto na
internet — quem não consegue abrir um arquivo na pasta não vê o relatório.

Ele então diz onde os arquivos realmente estão, quem consegue lê-los e o que
falta, item por item. **Apague `diagnostico.php` e `diagnostico.chave.txt` ao
terminar.**

### "403 Forbidden — Access to this resource on the server is denied!"

Esta mensagem é do servidor da Hostinger, não do sistema. Quatro causas, em
ordem de frequência:

1. **Uma pasta-pai sem permissão de travessia.** Esta é traiçoeira: não precisa
   ser a pasta publicada. Basta que qualquer pasta do caminho —
   `domains/`, `domains/seudominio.com.br/` — esteja com `700`. O servidor não
   consegue atravessá-la e responde 403 para tudo, inclusive para o
   `diagnostico.php`. Toda pasta do caminho precisa de `755`.
2. **O `index.php` não está solto no document root.** Ao descompactar, é comum
   sobrar um nível: `public_html/document-root/index.php` ou
   `public_html/recjota/...`. O `index.php` precisa estar diretamente no
   document root.
3. **Permissão do arquivo.** Arquivos precisam ser `644`. Com `600` o servidor
   não consegue ler e devolve 403.
4. **Um `.htaccess` que nega tudo.** Se sobrou no document root um `.htaccess`
   de outra instalação — ou o antigo arquivo de proteção deste projeto, que
   negava todo acesso — ele bloqueia inclusive o diagnóstico. Na dúvida,
   renomeie o `.htaccess` para `.htaccess.velho` e recarregue a página.

O `diagnostico.php` identifica as quatro: ele lista a permissão de cada pasta do
caminho, uma a uma, e marca a que está travando.

**Página em branco ou erro 500**
Quase sempre é permissão de escrita. Confira `app/storage/` e
`app/bootstrap/cache/` como na seção 5.

**"O sistema não conseguiu falar com o banco de dados"**
A própria tela lista o que conferir. Se você acabou de editar o `.env`, apague
os arquivos de `app/bootstrap/cache/` — a configuração em cache continua
valendo até isso.

**O site mostra a listagem de arquivos em vez do sistema**
Falta o `index.php` em `public_html`, ou sobrou um `index.html` antigo.

**Os e-mails não chegam**
Confira SMTP em `/manutencao` › Processar a fila agora, e veja
`app/storage/logs/`. Configure **SPF, DKIM e DMARC** no DNS — sem isso boa parte
dos provedores descarta a mensagem silenciosamente.

**O cron não roda**
Alguns planos levam até 20 minutos para a primeira execução. Se depois disso o
`/health` continuar acusando, confira o caminho absoluto no cadastro do cron: o
`u........._` precisa ser o seu.

---

## 11. Backup

A hospedagem compartilhada não garante backup próprio. Até a rotina automática
da Fase 7, faça manualmente:

- **Banco**: hPanel › Backups, ou phpMyAdmin › Exportar.
- **Arquivos**: guarde uma cópia do `app/.env` (é o que tem a `APP_KEY`) e da
  pasta `app/storage/app/`.

Retenção recomendada: 14 dias, fora do servidor.
