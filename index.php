<?php
session_start();

// Diretório para salvar imagens processadas
$uploadDir = 'uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$message = '';
$imagemProcessada = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['foto'])) {
    $file = $_FILES['foto'];
    
    // Validações
    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
    $maxSize = 10 * 1024 * 1024; // 10MB
    
    if (!in_array($file['type'], $allowedTypes)) {
        $message = '❌ Tipo de arquivo inválido. Envie JPG ou PNG.';
    } elseif ($file['size'] > $maxSize) {
        $message = '❌ Arquivo muito grande. Máximo 10MB.';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $message = '❌ Erro ao enviar arquivo.';
    } else {
        $nomeArquivo = time() . '_' . basename($file['name']);
        $caminhoCompleto = $uploadDir . $nomeArquivo;
        
        if (move_uploaded_file($file['tmp_name'], $caminhoCompleto)) {
            // Processar imagem e tampar placa
            $imagemProcessada = processarImagemComPlaca($caminhoCompleto);
            $message = '✅ Imagem processada com sucesso!';
        } else {
            $message = '❌ Erro ao salvar arquivo.';
        }
    }
}

function processarImagemComPlaca($caminhoImagem) {
    $imagem = imagecreatefromfile($caminhoImagem);
    
    if (!$imagem) {
        return null;
    }
    
    $largura = imagesx($imagem);
    $altura = imagesy($imagem);
    
    // Detectar e tampar placa
    $imagemProcessada = detectarETamparPlaca($imagem, $largura, $altura);
    
    // Salvar imagem processada
    $nomeProcessado = 'processada_' . basename($caminhoImagem);
    $caminhProcessado = 'uploads/' . $nomeProcessado;
    
    imagepng($imagemProcessada, $caminhProcessado);
    imagedestroy($imagem);
    imagedestroy($imagemProcessada);
    
    return $caminhProcessado;
}

function imagecreatefromfile($arquivo) {
    $info = getimagesize($arquivo);
    if (!$info) return false;
    
    switch ($info[2]) {
        case IMAGETYPE_JPEG:
            return imagecreatefromjpeg($arquivo);
        case IMAGETYPE_PNG:
            return imagecreatefrompng($arquivo);
        default:
            return false;
    }
}

function detectarETamparPlaca(&$imagem, $largura, $altura) {
    $imagemCopia = imagecreatetruecolor($largura, $altura);
    imagecopy($imagemCopia, $imagem, 0, 0, 0, 0, $largura, $altura);
    
    // Cor preta para cobrir a placa (RGBA)
    $preto = imagecolorallocate($imagemCopia, 0, 0, 0);
    
    // Estratégia simples: detectar áreas de contraste alto na parte inferior (onde costuma estar a placa)
    $zonas = detectarZonasDeAltoContraste($imagem, $largura, $altura);
    
    foreach ($zonas as $zona) {
        $x1 = $zona['x'];
        $y1 = $zona['y'];
        $x2 = $zona['x'] + $zona['w'];
        $y2 = $zona['y'] + $zona['h'];
        
        // Desenhar retângulo preto sobre a placa
        imagefilledrectangle($imagemCopia, $x1, $y1, $x2, $y2, $preto);
        
        // Adicionar barra desfocada cinza sobre a placa (efeito censura)
        $cinza = imagecolorallocate($imagemCopia, 100, 100, 100);
        imagefilledrectangle($imagemCopia, $x1 + 5, $y1 + 5, $x2 - 5, $y2 - 5, $cinza);
    }
    
    return $imagemCopia;
}

function detectarZonasDeAltoContraste(&$imagem, $largura, $altura) {
    $zonas = [];
    
    // Focar na parte inferior onde costuma estar a placa (últimos 30% da imagem)
    $inicioY = intval($altura * 0.65);
    $fimY = $altura;
    
    $limiteContraste = 80;
    $alturaMinimaPlaca = 20;
    $larguraMinimaPlaca = 80;
    
    for ($y = $inicioY; $y < $fimY - $alturaMinimaPlaca; $y += 5) {
        for ($x = 0; $x < $largura - $larguraMinimaPlaca; $x += 5) {
            $contraste = calcularContraste($imagem, $x, $y);
            
            if ($contraste > $limiteContraste) {
                $zona = expandirZona($imagem, $x, $y, $largura, $altura, $limiteContraste);
                
                if ($zona['w'] > $larguraMinimaPlaca && $zona['h'] > $alturaMinimaPlaca) {
                    $zonas[] = $zona;
                    break; // Evitar múltiplas detecções da mesma placa
                }
            }
        }
    }
    
    return $zonas;
}

function calcularContraste(&$imagem, $x, $y) {
    $pixels = [];
    for ($i = 0; $i < 5; $i++) {
        for ($j = 0; $j < 5; $j++) {
            $rgb = imagecolorat($imagem, $x + $i, $y + $j);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $pixels[] = ($r + $g + $b) / 3;
        }
    }
    
    $media = array_sum($pixels) / count($pixels);
    $variancia = 0;
    foreach ($pixels as $p) {
        $variancia += pow($p - $media, 2);
    }
    $variancia /= count($pixels);
    
    return sqrt($variancia);
}

function expandirZona(&$imagem, $x, $y, $largura, $altura, $limiteContraste) {
    $x1 = $x;
    $y1 = $y;
    $x2 = $x + 100;
    $y2 = $y + 40;
    
    // Expandir até encontrar borda
    while ($x1 > 0 && calcularContraste($imagem, $x1 - 5, $y) > $limiteContraste * 0.5) {
        $x1 -= 5;
    }
    
    while ($x2 < $largura && calcularContraste($imagem, $x2, $y) > $limiteContraste * 0.5) {
        $x2 += 5;
    }
    
    return [
        'x' => max(0, $x1),
        'y' => max(0, $y1),
        'w' => min($largura, $x2) - max(0, $x1),
        'h' => min($altura, $y2) - max(0, $y1)
    ];
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tampador de Placas - Grátis e Sem Limitações</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <header>
            <h1>🚗 Tampador de Placas</h1>
            <p>Censure placas veiculares automaticamente - Grátis, rápido e sem limitações</p>
        </header>

        <main>
            <form method="POST" enctype="multipart/form-data" class="formulario">
                <div class="area-upload">
                    <svg class="icone-upload" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="17 8 12 3 7 8"></polyline>
                        <line x1="12" y1="3" x2="12" y2="15"></line>
                    </svg>
                    <h2>Selecione ou arraste uma foto</h2>
                    <p>Envie JPG ou PNG (máx. 10MB)</p>
                    <input type="file" name="foto" id="foto" accept="image/*" required>
                </div>

                <button type="submit" class="btn-enviar">Tampar Placa 🔒</button>
            </form>

            <?php if ($message): ?>
                <div class="mensagem <?php echo strpos($message, '✅') !== false ? 'sucesso' : 'erro'; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <?php if ($imagemProcessada): ?>
                <div class="resultado">
                    <h2>Resultado</h2>
                    <img src="<?php echo htmlspecialchars($imagemProcessada); ?>" alt="Imagem processada">
                    <a href="<?php echo htmlspecialchars($imagemProcessada); ?>" download class="btn-download">
                        ⬇️ Download Imagem
                    </a>
                </div>
            <?php endif; ?>
        </main>

        <footer>
            <p>💡 Sistema grátis e sem limitações de uso</p>
            <p>Desenvolvido com ❤️ | <a href="https://github.com/welldeveloper/placa-tampada" target="_blank">GitHub</a></p>
        </footer>
    </div>

    <script>
        // Drag and drop
        const areUpload = document.querySelector('.area-upload');
        const inputFile = document.getElementById('foto');

        areUpload.addEventListener('dragover', (e) => {
            e.preventDefault();
            areUpload.classList.add('drag-over');
        });

        areUpload.addEventListener('dragleave', () => {
            areUpload.classList.remove('drag-over');
        });

        areUpload.addEventListener('drop', (e) => {
            e.preventDefault();
            areUpload.classList.remove('drag-over');
            inputFile.files = e.dataTransfer.files;
        });

        inputFile.addEventListener('change', () => {
            if (inputFile.files.length > 0) {
                document.querySelector('form').submit();
            }
        });
    </script>
</body>
</html>
