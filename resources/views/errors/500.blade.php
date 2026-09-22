@include('errors.layout', [
    'codigo' => 500,
    'titulo' => 'Algo deu errado do nosso lado',
    'oQueAconteceu' => 'Um erro inesperado interrompeu o que você estava fazendo. O ocorrido foi registrado.',
    'oQueFazer' => 'Tente de novo em instantes. Se persistir, avise o suporte da agência informando o horário e o que estava fazendo.',
    'rotulo' => 'Voltar ao início',
    'destino' => url('/'),
    'mensagem' => null,
])
