<?php
require_once('config.php');


class Bot
{
    private $bot_token;

    public function __construct($bot_token, $telegram_bot_api_key)
    {
        $this->bot_token = $bot_token;
        $this->telegram_bot_api_key = $telegram_bot_api_key;
    }

    public function handleUpdate($update)
    {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $text = isset($message['text']) ? $message['text'] : null;
        $voice = isset($message['voice']) ? $message['voice'] : null;
        $this->recordInteraction($chat_id);

        $user_type = $this->getUserType($chat_id);
        $daily_messages_count = $this->getDailyMessagesCount($chat_id);

        if ($user_type === 'free' && $daily_messages_count >= 10) {
            $this->sendTypingAction($chat_id);
            sleep(1);
            $this->sendMessage($chat_id, "⚠️ Désolé, vous avez atteint la limite quotidienne de 10 messages pour les utilisateurs gratuits. Passez à la version Pro pour profiter d'un nombre illimité de messages.");
            return;
        }


        if ($text !== null) {
            // Gérer les commandes et les messages texte
            switch ($text) {
                case '/start':
                    $this->sendTypingAction($chat_id);
                    sleep(1);
                    $welcome_message = "👋 Coucou 🐨🌿\nAvec moi, tu peux avoir la réponse à TOUT en quelques secondes !\n\n🇺🇸 🇬🇧 🇫🇷 🇪🇸 Je parle toutes les langues, envoi /prof pour commencer";
                    $this->sendMessage($chat_id, $welcome_message);
                    break;
                case '/help':
                    $this->sendTypingAction($chat_id);
                    sleep(1);
                    $help_message = "Besoin d'aide ? Voici ce que je peux faire : \n\n/start - Pour commencer\n/help - Pour obtenir de l'aide\n/prof - Pour commencer une session de chat avec un professeur virtuel";
                    $this->sendMessage($chat_id, $help_message);
                    break;
                default:
                    $this->sendTypingAction($chat_id);
                    sleep(1);
                    $response = $this->generateResponse($text, $chat_id);
                    $this->sendMessage($chat_id, $response);
            }

        } elseif ($voice !== null) {
            // Gérer les messages audio
            $this->sendTypingAction($chat_id);
            $file_id = $voice['file_id'];
            $transcribed_text = $this->getTranscribedText($file_id);
            $response = $this->generateResponse($transcribed_text, $chat_id);
            
            $this->sendMessage($chat_id, $response);
        }

    }

    private function getUserType($chat_id)
{
    $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASSWORD);

    $stmt = $db->prepare('SELECT user_type FROM users WHERE chat_id = :chat_id');
    $stmt->bindParam(':chat_id', $chat_id, PDO::PARAM_INT);
    $stmt->execute();

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        return $result['user_type'];
    } else {
        // Si l'utilisateur n'est pas encore dans la base de données, ajoutez-le en tant qu'utilisateur gratuit
        $this->addUser($chat_id, 'free');
        return 'free';
    }
}

private function getDailyMessagesCount($chat_id)
{
    $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASSWORD);

    $today_start = strtotime("today");
    $today_end = strtotime("tomorrow");

    $stmt = $db->prepare('SELECT COUNT(*) as daily_count FROM interac WHERE chat_id = :chat_id AND timestamp BETWEEN :today_start AND :today_end');
    $stmt->bindParam(':chat_id', $chat_id, PDO::PARAM_INT);
    $stmt->bindParam(':today_start', $today_start, PDO::PARAM_INT);
    $stmt->bindParam(':today_end', $today_end, PDO::PARAM_INT);
    $stmt->execute();

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    return $result['daily_count'];
}

private function addUser($chat_id, $user_type)
{
    $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASSWORD);

    $stmt = $db->prepare('INSERT INTO users (chat_id, user_type) VALUES (:chat_id, :user_type)');
    $stmt->bindParam(':chat_id', $chat_id, PDO::PARAM_INT);
    $stmt->bindParam(':user_type', $user_type, PDO::PARAM_STR);
    $stmt->execute();
}

private function recordInteraction($chat_id)
{
    $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASSWORD);

    $timestamp = time();

    $stmt = $db->prepare('INSERT INTO interac (chat_id, timestamp) VALUES (:chat_id, :timestamp)');
    $stmt->bindParam(':chat_id', $chat_id, PDO::PARAM_INT);
    $stmt->bindParam(':timestamp', $timestamp, PDO::PARAM_INT);
    $stmt->execute();
}




    private function getFile($file_id)
    {
        $url = "https://api.telegram.org/bot" . $this->telegram_bot_api_key . "/getFile?file_id=" . $file_id;
        $response = file_get_contents($url);
        $response_obj = json_decode($response, true);
    
        return $response_obj['result'];
    }
    
    private function convertAudioToMp3($input_file_path, $output_file_path)
    {
        // Remplacez ceci par le chemin correct vers votre fichier FFmpeg
        $ffmpeg_binary = 'ffmpeg/ffmpeg';
    
        $command = escapeshellcmd($ffmpeg_binary . ' -i ' . $input_file_path . ' ' . $output_file_path);
        $output = [];
        $return_code = 0;
        exec($command, $output, $return_code);
    
        if ($return_code != 0) {
            throw new Exception("Error converting audio file to MP3: " . implode("\n", $output));
        }
    }
    
    private function getTranscribedText($file_id)
    {
        // Récupérer le fichier audio à partir de Telegram
        $file = $this->getFile($file_id);
        $file_path = 'https://api.telegram.org/file/bot' . $this->telegram_bot_api_key . '/' . $file['file_path'];
    
        // Télécharger le fichier audio localement
        $audio_file_path = 'audio/' . $file_id . '.oga';
        file_put_contents($audio_file_path, file_get_contents($file_path));
    
        // Convertir l'audio en MP3 à l'aide de FFmpeg
        $mp3_audio_file_path = 'audio2/' . $file_id . '.mp3';
        $this->convertAudioToMp3($audio_file_path, $mp3_audio_file_path);
    
        // Appeler l'API OpenAI pour transcrire l'audio
        $model = 'whisper-1';
        $response_format = 'json';
    
        $curl = curl_init();
    
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api.openai.com/v1/audio/transcriptions',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => array(
                'file' => new CURLFile($mp3_audio_file_path),
                'model' => $model,
                'response_format' => $response_format
            ),
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . OPENAI_API_KEY
            ),
        ));
    
        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
    
        // Supprimer les fichiers audio temporaires
        unlink($audio_file_path);
        unlink($mp3_audio_file_path);
        
    
        // Parser la réponse JSON
        $response_obj = json_decode($response, true);
    
        return $response_obj['text'];
        
    }

    private function generateResponse($text, $chat_id)
    {

        // Utilisez les constantes pour se connecter à la base de données
        $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASSWORD);

        // Définir le nombre maximal d'interactions à enregistrer
        $max_interactions = 100;

        // Récupérer le message envoyé par l'utilisateur
        $message = $text;

        // Initialiser la variable $content
        $content = '';

        // Récupérer les interactions précédentes depuis la base de données
        $stmt = $db->prepare('SELECT * FROM interactions WHERE chat_id = :chat_id ORDER BY id DESC LIMIT :limit');
        $stmt->bindParam(':chat_id', $chat_id, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $max_interactions, PDO::PARAM_INT);
        $stmt->execute();
        $previous_interactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Vérifier si la requête a renvoyé des résultats
        if (!is_array($previous_interactions)) {
            $previous_interactions = array();
        }

        // Ajouter le message de l'utilisateur à la liste des interactions
        $new_interaction = array(
            'message' => $message,
            'response' => $content,
            'timestamp' => time()
        );

        array_push($previous_interactions, $new_interaction);

        // Tronquer la liste des interactions pour ne garder que les dernières interactions
        if (count($previous_interactions) > $max_interactions) {
            $previous_interactions = array_slice($previous_interactions, -$max_interactions);
        }

        // Enregistrer les interactions mises à jour dans la base de données
        $db->beginTransaction();

        $stmt = $db->prepare('DELETE FROM interactions WHERE chat_id = :chat_id');
        $stmt->bindParam(':chat_id', $chat_id, PDO::PARAM_INT);
        $stmt->execute();

        $stmt = $db->prepare('INSERT INTO interactions (id, chat_id, message, timestamp) VALUES (NULL, :chat_id, :message, :timestamp)');
        foreach ($previous_interactions as $interaction) {
            $stmt->bindParam(':chat_id', $chat_id, PDO::PARAM_INT);
            $stmt->bindParam(':message', $interaction['message']);
            $stmt->bindParam(':timestamp', $interaction['timestamp']);
            $stmt->execute();
        }

        $db->commit();

            // Définir les informations d'API OpenAI
            $openai_api_key = OPENAI_API_KEY;
            $model = 'gpt-3.5-turbo';
        
            // Ajouter la nouvelle question à la liste des interactions précédentes
            $new_interaction = array(
                'message' => $message,
                'timestamp' => time()
            );
            array_push($previous_interactions, $new_interaction);
        
            // Préparer les données à envoyer à l'API OpenAI
            $data = array(
                'model' => $model,
                'messages' => array(),
                'temperature' => 0.5,
                'max_tokens' => 100,
                'stop' => '\n',
                'presence_penalty' => 0.5,
                'frequency_penalty' => 0.5,
            );
        
            // Ajouter les interactions précédentes (y compris la nouvelle question) à la liste des messages
            if (is_array($previous_interactions)) {
                $first_message = true;
                foreach ($previous_interactions as $interaction) {
                    if ($first_message) {
                        array_push($data['messages'], array(
                            'role' => 'assistant',
                            'content' => 'Je suis professeur virtuel, je m\'appelle Magical prof et j\'ai été créé par le groupe Blocksdev en 2020, je réponds à la question avec intelligence.',
                        ));
                        $first_message = false;
                    }
                    array_push($data['messages'], array(
                        'role' => 'user',
                        'content' => $interaction['message']
                    ));
                    if (isset($interaction['response'])) {
                        array_push($data['messages'], array(
                            'role' => 'assistant',
                            'content' => $interaction['response']
                        ));
                    }
                }
            }
        
            // Effectuer l'appel à l'API OpenAI en utilisant la bibliothèque cURL
            $curl = curl_init();
        
            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://api.openai.com/v1/chat/completions',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $openai_api_key
                ),
            ));
        
            $response = curl_exec($curl);
        
            curl_close($curl);
        
            // Parser la réponse JSON
            $response_obj = json_decode($response, true);
        
            if (isset($response_obj['choices'][0]['message']['content'])) {
                // The key exists, do something here
                $content = $response_obj['choices'][0]['message']['content'];
            } else {
                // The key doesn't exist
                $content = "Something went wrong! ```" . json_encode($response_obj) . "```";
            }
        
                // Ajouter la réponse générée à la liste des interactions si elle existe
        if (isset($message) && isset($content)) {
            $new_interaction = array(
                'message' => $message,
                'response' => $content,
                'timestamp' => time()
            );
            array_push($previous_interactions, $new_interaction);

            // Enregistrer les interactions mises à jour dans la base de données
            $db->beginTransaction();

            $stmt = $db->prepare('DELETE FROM interactions WHERE chat_id = :chat_id');
            $stmt->bindParam(':chat_id', $chat_id, PDO::PARAM_INT);
            $stmt->execute();

            $stmt = $db->prepare('INSERT INTO interactions (id, chat_id, message, response, timestamp) VALUES (NULL, :chat_id, :message, :response, :timestamp)');
            foreach ($previous_interactions as $interaction) {
                $stmt->bindParam(':chat_id', $chat_id, PDO::PARAM_INT);
                $stmt->bindParam(':message', $interaction['message']);
                $stmt->bindParam(':response', $interaction['response']);
                $stmt->bindParam(':timestamp', $interaction['timestamp']);
                $stmt->execute();
            }
            
            // Supprimer les enregistrements avec des colonnes vides pour chaque utilisateur
                    $stmt = $db->prepare('DELETE FROM interactions WHERE response = "" OR response IS NULL');
                    $stmt->execute();

            $db->commit();
        }

        // Renvoyer la réponse générée
        return $content;
    }

    private function sendMessage($chat_id, $text)
    {
        $url = "https://api.telegram.org/bot" . $this->telegram_bot_api_key . "/sendMessage?chat_id=" . $chat_id . "&text=" . urlencode($text);
        file_get_contents($url);
    }

    private function sendTypingAction($chat_id)
    {
        $url = "https://api.telegram.org/bot" . $this->telegram_bot_api_key . "/sendChatAction?chat_id=" . $chat_id . "&action=typing";
        file_get_contents($url);
    }
}


$update = json_decode(file_get_contents('php://input'), true);

if (isset($update)) {
    $bot = new Bot('votre_token_bot_telegram', TELEGRAM_BOT_API_KEY);
    $bot->handleUpdate($update);
}
