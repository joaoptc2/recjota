@include('errors.layout', [
    'codigo' => 419,
    'titulo' => 'A página expirou',
    'oQueAconteceu' => 'O formulário ficou aberto por muito tempo e a sessão venceu antes do envio.',
    'oQueFazer' => 'Volte, recarregue a página e envie de novo. O que você digitou não foi salvo.',
    'rotulo' => 'Voltar',
    'destino' => url()->previous() !== url()->current() ? url()->previous() : url('/'),
    'mensagem' => null,
])
