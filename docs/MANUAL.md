# Manual do usuário — Recjota

Este manual cobre o dia a dia de quem usa o sistema: a equipe da agência (no
**painel**) e as pessoas do cliente (no **portal**). Para instalar e manter o
servidor, veja o [DEPLOY.md](../DEPLOY.md).

Todo horário mostrado na tela está no **fuso do cliente** (padrão: horário de
Brasília). Todo dado que a plataforma não informa aparece como
**"indisponível"**, nunca como zero.

---

## 1. Papéis: quem vê o quê

| Papel | Onde entra | Pode |
|---|---|---|
| Proprietário (owner) | painel | tudo, inclusive excluir cliente e desativar outros proprietários |
| Administrador (admin) | painel | tudo, menos excluir cliente e faturamento |
| Gestor de contas | painel | seus clientes: posts, aprovações, mídia, integrações, relatórios |
| Criador de conteúdo | painel | seus clientes: criar e editar posts, subir mídia; não publica nem aprova |
| Administrador do cliente | portal | aprovar, comentar, ver relatórios, convidar pessoas do cliente |
| Leitor do cliente | portal | ver posts, calendário e relatórios |

Gestores e criadores só enxergam os clientes aos quais foram vinculados. Um
usuário do cliente A nunca vê nada do cliente B — nem digitando o endereço.

---

## 2. Primeiro acesso da agência

1. Entre com o e-mail e a senha criados na instalação.
2. **Configurações** › *Dados e branding*: nome da agência, e-mail de suporte e
   cor primária. Aparecem nos e-mails e nos relatórios.
3. **Configurações** › *Usuários*: convide a equipe. Cada convite chega por
   e-mail com link para definir a senha.
4. **Clientes**: cadastre o cliente (nome, fuso, cor e logo — o logo e a cor
   valem para o portal e para o PDF do relatório).
5. Na página do cliente, **Conectar Instagram**. Autorize com um perfil que
   administre a conta profissional (Business ou Creator). Sem isso os posts
   aprovados não têm para onde ir.
6. Opcional: **Conectar Google Drive** ou **OneDrive** do cliente, para
   escolher imagens direto da nuvem.
7. Vincule as pessoas do cliente (papel *administrador do cliente* para quem
   aprova).

---

## 3. Criar e agendar um post

**Calendário › Novo post** ou, na página do cliente, **Novo post**.

- **Tipo**: imagem, vídeo, reel, carrossel ou story. Stories só funcionam em
  conta Business.
- **Conta**: se o cliente tem mais de uma conta conectada, escolha qual publica.
  É possível marcar contas extras: o sistema cria um post por conta.
- **Mídia**: *Escolher da biblioteca* abre a biblioteca do cliente, onde você
  envia arquivos do computador ou importa do Google Drive/OneDrive. Carrossel
  aceita de 2 a 10 itens; arraste as setas para ordenar. Preencha o **texto
  alternativo** de cada imagem.
- **Legenda**: os contadores mostram caracteres (máximo 2.200), hashtags (30)
  e menções (50). Passou de 125 caracteres, o feed corta com "… mais".
- **Primeiro comentário**: publicado logo depois do post (bom para hashtags).
- **Agendamento**: data e hora no fuso do cliente; o horário UTC aparece ao
  lado. Se a conta já tem 30 posts medidos, o sistema sugere os melhores
  horários pelo histórico de engajamento.
- O rascunho salva sozinho a cada 20 segundos.

**Enviar para aprovação** bloqueia se algo estiver errado (mídia ausente,
legenda longa, data no passado, formato incompatível) — e diz o quê.

### O que acontece depois

| Status | Significado |
|---|---|
| Rascunho | só a equipe vê |
| Em revisão | revisão interna (se o cliente exige) |
| Aguardando cliente | o cliente recebeu o e-mail e ainda não decidiu |
| Ajustes solicitados / Reprovado | volte ao post, leia o comentário, corrija e reenvie |
| Aprovado / Agendado | sai automaticamente na hora marcada |
| Publicando | o sistema está falando com o Instagram (leva de 1 a 5 minutos) |
| Publicado | no ar; o link aparece no post |
| Falhou | leia a mensagem: ela diz o que houve e o que fazer |

**Editou um post já aprovado?** Ele volta para "aguardando cliente" e um novo
e-mail sai. O sistema nunca publica uma versão diferente da aprovada.

---

## 4. Aprovar (lado do cliente)

O cliente recebe um e-mail com **link direto**, sem senha. A tela mostra a
mídia como vai aparecer, a legenda, a data prevista e os comentários. Três
botões: **Aprovar**, **Solicitar ajustes** (exige comentário) e **Reprovar**
(exige motivo). Funciona no celular.

No portal, **Aprovações** lista tudo que está pendente, com "aprovar todos".

O link vale até a data configurada (padrão 48 h) e um número limitado de usos.
Um lembrete sai 24 h antes do prazo. Se o post mudar, o link antigo deixa de
valer e outro é enviado.

Na agência, **Aprovações** mostra o que está travado e com quem.

---

## 5. Biblioteca de mídia

**Biblioteca** no menu, por cliente.

- **Enviar arquivos**: JPEG, PNG, WebP, GIF, MP4 ou MOV. O sistema confere o
  tipo real do arquivo, gera miniatura e descarta duplicatas.
- **Google Drive**: clique em *Escolher do Google Drive* › *Abrir o Google
  Drive* e selecione. O Google só mostra ao sistema o que você escolher.
- **OneDrive**: clique em *Escolher do OneDrive* e navegue pelas pastas;
  *Importar* no arquivo.
- O original de Drive/OneDrive **continua na nuvem**; aqui fica só a miniatura.
  Na hora de publicar, o sistema baixa o arquivo, envia ao Instagram e apaga a
  cópia. Se a conexão com a nuvem expirar, o post falha com a instrução de
  reconectar.

Antes do agendamento, a mídia é validada contra as regras do Instagram
(proporção, tamanho, duração). O composer avisa o que ajustar.

---

## 6. Publicação automática

Um post aprovado sai sozinho na hora marcada. Se o Instagram estiver
instável, o sistema tenta de novo (1 min, 5 min, 15 min, 1 h, 4 h) e só
depois desiste, avisando o gestor por e-mail com o motivo.

Erros que não adianta repetir (token revogado, mídia recusada, conta
desconectada) param na hora, com a instrução na tela do post.

**Cota**: o Instagram aceita 50 publicações por conta a cada 24 h. Se estourar,
o post é reagendado para a próxima janela livre e o gestor é avisado.

Em **Integrações** há um semáforo por conta: verde publica; vermelho pede
**Reconectar**. Tokens são renovados sozinhos; se a renovação falhar, chega um
e-mail antes de o token vencer.

---

## 7. Métricas e relatórios

**Relatórios** no menu (agência) ou no portal (cliente).

- Escolha o período: 7, 30, 90 dias ou o mês atual.
- Cartões: seguidores (com variação), alcance (contra o período anterior),
  impressões e engajamento médio; gráficos de alcance e seguidores por dia;
  os 5 melhores posts; comparativo dos últimos 6 meses.
- **Exportar CSV**: abre no Excel; célula vazia significa "indisponível".
- **Gerar PDF**: escolha o mês. O relatório usa logo e cor do cliente. No dia 1
  de cada mês o sistema gera o do mês anterior e envia por e-mail aos
  administradores do cliente e aos gestores, com o PDF anexo. A lista de PDFs
  fica na mesma tela, para baixar quando quiser.

Os números vêm da API do Instagram na madrugada (04:10 e 04:40 UTC). Uma conta
recém-conectada mostra dados a partir do dia seguinte.

---

## 8. Tarefas, campanhas e agenda

- **Tarefas**: quadro por cliente e visão "minhas tarefas". Uma tarefa pode
  apontar para um post ou campanha.
- **Campanhas**: agrupam posts e aparecem no calendário.
- **Calendário**: mês, semana, dia e lista; arraste um post para reagendar
  (posts publicados não se movem). Eventos e datas comemorativas ficam numa
  camada que você liga e desliga.

---

## 9. Configurações e saúde do sistema

**Configurações** (proprietário e administrador):

- **Saúde do sistema**: último batimento do cron (se ficou mais de 10 minutos
  sem bater, algo parou), jobs falhados com botão *Tentar de novo*, tokens que
  vencem em 7 dias, e os **backups do banco** para download.
- **Dados e branding**, **Usuários** (convidar, desativar) e **Convites
  pendentes**.

Alertas automáticos por e-mail ao proprietário: cron parado, jobs falhados,
tokens vencendo.

---

## 10. Dados do cliente (LGPD)

Na página do cliente:

- **Exportar dados (.zip)** — tudo que existe sobre o cliente, em JSON, mais as
  miniaturas. Sem tokens nem senhas.
- **Excluir definitivamente** — só o proprietário, digitando o nome do cliente.
  Apaga posts, mídias, relatórios, contas conectadas e os usuários do portal
  que só pertenciam a ele. Não há como desfazer.

Consentimento: ao conectar Instagram, Drive ou OneDrive, o sistema registra
quem autorizou, quando e com quais permissões.

---

## 11. Quando algo dá errado

Toda mensagem de erro do sistema diz **o que aconteceu** e **o que fazer**.
Os casos mais comuns:

| Mensagem | O que fazer |
|---|---|
| "A conta … está desconectada" | Integrações › Reconectar, com um perfil que administre a conta |
| "O Instagram recusou a mídia" | confira formato, proporção e duração; substitua o arquivo |
| "Atingiu o limite de 50 publicações" | nada; o post foi reagendado sozinho |
| "A conexão com o Google Drive precisa ser refeita" | página do cliente › Reconectar Google Drive |
| "O cron não bate" | peça a quem administra a hospedagem para conferir o cron job |
| Post "Falhou" 6 vezes | leia o último erro no post, corrija e reagende |

Se a tela mostrar um erro 500, tente de novo em instantes; se persistir, avise
o suporte da agência com o horário e o que estava fazendo.
