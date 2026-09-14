# Instalação na Hostinger (sem SSH, sem Composer, sem Node no servidor)

O servidor não roda Composer, não roda Node e não precisa de linha de comando.
Tudo o que ele recebe é um pacote de arquivos; o resto acontece pelo navegador.

Fluxo completo: **baixar o pacote → enviar por FTP → abrir o site → cadastrar o
cron.** Leva uns 20 minutos na primeira vez.

---

## 1. Obter o pacote de instalação

O pacote já vem com as bibliotecas instaladas (`vendor/`), o CSS e o JavaScript
compilados (`build/`) e uma chave de criptografia gerada. Cerca de 20 MB.

**Opção A — GitHub Actions (nada instalado na sua máquina)**

Aba **Actions** › workflow **Pacote de instalação** › **Run workflow**. Ao
terminar, baixe o `.zip` na seção **Artifacts**.

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

## 4. Enviar os arquivos

Descompacte o `.zip` na sua máquina. Dentro dele há duas pastas — elas vão para
lugares diferentes:

```
/home/uXXXXXXXX/domains/seudominio.com.br/
├── app/           ← a pasta "app" do pacote (FORA do document root)
└── public_html/   ← o conteúdo da pasta "public_html" do pacote
```

Envie pelo **Gerenciador de Arquivos** do hPanel (mais simples: dá para subir o
`.zip` e descompactar lá dentro) ou por qualquer cliente **FTP**.

Se já existir um `index.html` ou `default.php` em `public_html`, apague — ele
tem prioridade sobre o `index.php` do sistema.

### Por que duas pastas

`public_html` é o document root e fica exposto na internet. O código, o `.env`,
o `storage/` e o `vendor/` ficam em `app/`, um nível acima, fora do alcance de
qualquer visitante (R9).

---

## 5. Abrir o site

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

## 6. Cadastrar o cron — passo obrigatório

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

## 7. Console de manutenção — o terminal que a hospedagem não tem

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

## 8. Atualizar o sistema depois

1. Gere um pacote novo (seção 1).
2. Envie por FTP **apenas a pasta `app/`** e a pasta `public_html/build/`,
   sobrescrevendo. **Não sobrescreva o `.env`** — ele tem as suas credenciais e
   a sua `APP_KEY`.
3. Abra `/manutencao` e clique, nesta ordem: **Atualizar o banco** → **Limpar os
   caches** → **Reconstruir os caches**.

---

## 9. Problemas comuns

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

## 10. Backup

A hospedagem compartilhada não garante backup próprio. Até a rotina automática
da Fase 7, faça manualmente:

- **Banco**: hPanel › Backups, ou phpMyAdmin › Exportar.
- **Arquivos**: guarde uma cópia do `app/.env` (é o que tem a `APP_KEY`) e da
  pasta `app/storage/app/`.

Retenção recomendada: 14 dias, fora do servidor.
