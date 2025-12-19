<?php
/**
 * 設定: .envファイルを読み込む簡易関数 (ライブラリ不使用のため自作)
 */
function loadEnv($path)
{
    if (!file_exists($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// .envをロード
loadEnv(__DIR__ . '/.env');

/**
 * API処理パート (POSTリクエスト時のみ実行)
 * ユーザーの入力から重要な語を選び、英訳して plain text で返す
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: text/plain; charset=utf-8');

    // 入力の取得
    $inputJSON = file_get_contents('php://input');
    $input = json_decode($inputJSON, true);
    $userMessage = $input['message'] ?? '';

    if (empty($userMessage)) {
        echo "エラー: 入力が空です。";
        exit;
    }

    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey) {
        echo "エラー: APIキーが .env に設定されていません。";
        exit;
    }

    // OpenAI API エンドポイント
    $url = 'https://api.openai.com/v1/chat/completions';

    // GPTへの指示 (システムプロンプト)
    // 「重要な語を1つ選び、原語とその英訳を返す」という指示
    $systemPrompt = "You are a linguistic assistant. Analyze the user's input, identify the single most important word (keyword), and provide only that word and its English translation. Output format must be strictly: 'OriginalWord - EnglishTranslation'. Do not include any other text.";

    $data = [
        'model' => 'gpt-5-mini', // 指定されたモデル
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage],
        ],
        'temperature' => 0.3,
    ];

    // cURLによるAPIリクエスト (ライブラリ不使用)
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: ' . 'Bearer ' . $apiKey
    ]);

    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        echo 'cURL Error: ' . curl_error($ch);
        exit;
    }
    
    curl_close($ch);

    // レスポンスの解析
    $responseData = json_decode($response, true);

    if (isset($responseData['error'])) {
        // API側からのエラー (モデルが存在しない場合など)
        echo "API Error: " . $responseData['error']['message'];
    } elseif (isset($responseData['choices'][0]['message']['content'])) {
        // 成功時のテキスト出力
        echo trim($responseData['choices'][0]['message']['content']);
    } else {
        echo "予期せぬエラーが発生しました。";
    }

    // API処理終了
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>重要語抽出・翻訳ボット</title>
    <style>
        body { font-family: sans-serif; max-width: 600px; margin: 2rem auto; padding: 0 1rem; }
        .chat-container { border: 1px solid #ddd; padding: 1rem; border-radius: 8px; min-height: 200px; margin-bottom: 1rem; background: #f9f9f9; }
        .message { margin-bottom: 0.5rem; padding: 0.5rem; border-radius: 4px; }
        .user { background-color: #e3f2fd; text-align: right; }
        .bot { background-color: #e8f5e9; font-weight: bold; color: #2e7d32; }
        .input-group { display: flex; gap: 10px; }
        input[type="text"] { flex-grow: 1; padding: 10px; font-size: 16px; }
        button { padding: 10px 20px; font-size: 16px; cursor: pointer; }
    </style>
</head>
<body>

    <h2>重要語翻訳チャットボット</h2>
    <p>文章を入力すると、重要な単語を1つ選び英訳します。</p>

    <div id="chat-box" class="chat-container">
        </div>

    <div class="input-group">
        <input type="text" id="user-input" placeholder="例: 明日は新幹線で東京に行きます" autofocus>
        <button onclick="sendMessage()">送信</button>
    </div>

    <script>
        async function sendMessage() {
            const inputField = document.getElementById('user-input');
            const chatBox = document.getElementById('chat-box');
            const text = inputField.value.trim();

            if (!text) return;

            // ユーザーのメッセージを表示
            chatBox.innerHTML += `<div class="message user">${escapeHtml(text)}</div>`;
            inputField.value = '';

            try {
                // 自分自身(index.php)にPOST送信
                const response = await fetch('index.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ message: text })
                });

                // plain text で返ってくるレスポンスを取得
                const resultText = await response.text();

                // ボットの回答を表示
                chatBox.innerHTML += `<div class="message bot">${escapeHtml(resultText)}</div>`;
                
            } catch (error) {
                chatBox.innerHTML += `<div class="message bot" style="color:red">エラーが発生しました</div>`;
            }

            // スクロールを下へ
            chatBox.scrollTop = chatBox.scrollHeight;
        }

        // Enterキーでも送信可能に
        document.getElementById('user-input').addEventListener('keypress', function (e) {
            if (e.key === 'Enter') sendMessage();
        });

        // HTMLエスケープ処理
        function escapeHtml(text) {
            return text
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }
    </script>
</body>
</html>