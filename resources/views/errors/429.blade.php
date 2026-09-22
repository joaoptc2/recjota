@include('errors.layout', [
    'codigo' => 429,
    'titulo' => 'Muitas tentativas em pouco tempo',
    'oQueAconteceu' => 'O sistema limita a quantidade de tentativas seguidas para proteger a sua conta.',
    'oQueFazer' => 'Aguarde um minuto e tente novamente. Se foi um link de aprovação, abra-o direto do e-mail em vez de recarregar várias vezes.',
    'rotulo' => 'Voltar ao início',
    'destino' => url('/'),
    'mensagem' => null,
])
