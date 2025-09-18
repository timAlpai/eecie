import uvicorn
from fastapi import FastAPI, Depends, HTTPException, status
from fastapi.security import APIKeyHeader
import socketio
import redis.asyncio as redis
from jose import jwt, JWTError # <-- CORRECTION 1
from dotenv import load_dotenv
import os
import json
import asyncio
import requests # Importez la bibliothèque requests
from requests.auth import HTTPBasicAuth
load_dotenv()

# --- Configuration ---
WP_API_URL = os.getenv('WP_API_URL')
WP_SERVICE_USER_EMAIL = os.getenv('WP_SERVICE_USER_EMAIL')
WP_SERVICE_USER_PASSWORD = os.getenv('WP_SERVICE_USER_PASSWORD')
INTERNAL_API_KEY = os.getenv('INTERNAL_API_KEY')
SHARED_SECRET_KEY_JWT = os.getenv('SHARED_SECRET_KEY_JWT')
REDIS_HOST = os.getenv('REDIS_HOST', 'localhost')
REDIS_PORT = int(os.getenv('REDIS_PORT', 6379))
API_KEY_HEADER = APIKeyHeader(name="X-Internal-API-Key", auto_error=False)

# --- Initialisation des serveurs ---
sio = socketio.AsyncServer(async_mode='asgi', cors_allowed_origins='*')
socket_app = socketio.ASGIApp(sio, socketio_path='socket.io')
app = FastAPI()
redis_client = None

# --- Démarrage et Arrêt ---
@app.on_event("startup")
async def startup_event():
    global redis_client
    redis_client = await redis.Redis(host=REDIS_HOST, port=REDIS_PORT, decode_responses=True)
    asyncio.create_task(redis_subscriber())

@app.on_event("shutdown")
async def shutdown_event():
    await redis_client.close()

# --- Logique de Sécurité ---
def verify_jwt(token: str) -> dict | None:
    try:
        decoded_token = jwt.decode(token, SHARED_SECRET_KEY_JWT, algorithms=["HS256"])
        return decoded_token
    except JWTError: # <-- CORRECTION 2
        return None

async def verify_internal_api_key(api_key: str = Depends(API_KEY_HEADER)):
    if not api_key or api_key != INTERNAL_API_KEY:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid Internal API Key")

# --- API REST Interne ---
@app.post("/api/event/{event_type}", dependencies=[Depends(verify_internal_api_key)])
async def handle_internal_event(event_type: str, data: dict):
    user_id = data.get("user_id")
    if user_id: # Événement pour un utilisateur spécifique
        channel = f"channel:user:{user_id}"
        message = json.dumps({"event": event_type, "data": data})
        await redis_client.publish(channel, message)
        return {"status": "event published to user channel"}
    
    # NOUVEAU : Gérer les événements globaux ou de groupe
    elif data.get("room") == "admins_room":
        channel = "channel:admins"
        message = json.dumps({"event": event_type, "data": data})
        await redis_client.publish(channel, message)
        return {"status": "event published to admin channel"}
        
    return {"status": "error", "message": "No target user_id or room specified"}

# --- Gestionnaires WebSocket ---
@sio.event
async def connect(sid, environ):
    print(f"➡️ [CONNECT] Client connecté: {sid}.")
    await sio.emit('welcome', {'message': 'Veuillez vous authentifier.'}, to=sid)

@sio.event
async def authenticate(sid, data):
    decoded_token = verify_jwt(data.get('token'))
    if not decoded_token or 'user_id' not in decoded_token:
        await sio.disconnect(sid)
        return
        
    user_id = decoded_token['user_id']
    is_admin = decoded_token.get('is_admin', False)
    
    # --- DÉBUT DE LA CORRECTION ---
    
    # On stocke TOUJOURS le mapping dans Redis, pour tout le monde.
    await redis_client.hset("user_connections", str(user_id), sid)
    
    await sio.save_session(sid, {
        'user_id': user_id, 
        'is_admin': is_admin, 
        'jwt': data.get('token')
    })


    # La logique de la salle reste, elle est pour la diffusion de groupe.
    if is_admin:
        await sio.enter_room(sid, 'admins_room')
        print(f"✅ Admin (user_id {user_id}) authentifié ET mappé dans Redis. SID: {sid}")
        
        # Envoyer la liste initiale des connectés
        connected_ids = await redis_client.hkeys("user_connections")
        await sio.emit('user_list_update', {'count': len(connected_ids), 'ids': connected_ids}, to=sid)
    else:
        print(f"✅ Employé (user_id {user_id}) authentifié et mappé dans Redis. SID: {sid}")
        
        # Notifier les admins de la nouvelle connexion via Pub/Sub
        admin_update_message = json.dumps({
            "event": "user_list_update", "data": {'action': 'connect', 'user_id': user_id}
        })
        await redis_client.publish("channel:admins", admin_update_message)

    await sio.emit('authenticated', {'status': 'success'}, to=sid)
    
    # --- FIN DE LA CORRECTION ---

@sio.event
async def disconnect(sid):
    session = await sio.get_session(sid)
    if session and 'user_id' in session:
        user_id = session['user_id']
        is_admin = session.get('is_admin', False)

        # On supprime TOUJOURS le mapping de Redis
        await redis_client.hdel("user_connections", str(user_id))
        
        # On notifie les admins uniquement si ce n'était pas un admin qui s'est déconnecté
        if not is_admin:
            print(f"Employé déconnecté: {sid} (user_id {user_id}). Notification envoyée.")
            admin_update_message = json.dumps({
                "event": "user_list_update", "data": {'action': 'disconnect', 'user_id': user_id}
            })
            await redis_client.publish("channel:admins", admin_update_message)
        else:
            print(f"Admin déconnecté: {sid} (user_id {user_id}).")

@sio.event
async def send_message(sid, data):
    print(f"➡️ [RECEIVE] Tentative d'envoi de message reçue de SID: {sid}")
    session = await sio.get_session(sid)
    
    if not session or 'jwt' not in session:
        print(f"❌ [ERROR] Client non authentifié {sid} a essayé d'envoyer un message. Session: {session}")
        return {'status': 'error', 'error': 'Non authentifié.'}

    thread_id = data.get('thread_id')
    content = data.get('content')

    if not thread_id or not content:
        print(f"❌ [ERROR] Paramètres manquants pour le message de SID: {sid}")
        return {'status': 'error', 'error': 'Paramètres manquants.'}

    try:
        api_url = f"{WP_API_URL}/messages"
        headers = {'Content-Type': 'application/json', 'Authorization': f'Bearer {session["jwt"]}'}
        payload = {'thread_id': thread_id, 'content': content}
        
        print(f"  [HTTP REQUEST] Envoi vers: {api_url}")
        print(f"  [HTTP REQUEST] Avec le token: Bearer {session['jwt'][:15]}...") # Affiche le début du token

        loop = asyncio.get_running_loop()
        response = await loop.run_in_executor(
            None, 
            lambda: requests.post(
                api_url,
                json=payload,
                headers=headers,
                timeout=10, # On ajoute un timeout de 10 secondes
                verify=False # IMPORTANT: On désactive la vérification SSL pour ce test
            )
        )
        
        # Le code ci-dessous vérifie la réponse de WordPress
        response.raise_for_status() # Lève une exception si le code est 4xx ou 5xx

        print(f"✅ [SUCCESS] Message de SID {sid} relayé avec succès à WordPress.")
        return {'status': 'ok'}

    except requests.exceptions.RequestException as e:
        # Erreur spécifique à la requête (connexion, timeout, SSL...)
        print(f"💥 [CRITICAL] Erreur de requête vers WordPress: {e}")
        return {'status': 'error', 'error': f'Erreur de communication avec WordPress.'}
    except Exception as e:
        # Toute autre erreur interne
        print(f"💥 [CRITICAL] Erreur interne inattendue en relayant le message: {e}")
        return {'status': 'error', 'error': 'Erreur interne du serveur.'}



# --- Écouteur Redis Pub/Sub (AVEC AJOUTS DE LOGS) ---
# --- Écouteur Redis Pub/Sub (Version finale et complète) ---
async def redis_subscriber():
    # ... (code de connexion inchangé)
    async with redis_client.pubsub() as pubsub:
        await pubsub.psubscribe("channel:user:*")
        await pubsub.subscribe("channel:admins")
        print("🚀 [REDIS] Écouteur Pub/Sub démarré.")
        
        while True:
            try:
                message = await pubsub.get_message(ignore_subscribe_messages=True, timeout=1.0)
                if not message:
                    continue

                print(f"🔵 [SUBSCRIBE] Message reçu de Redis: {message}")

                channel_name = message["channel"]
                event_data = json.loads(message["data"])
                event_name = event_data['event']
                event_payload = event_data.get('data', {}) # Utiliser .get pour plus de sécurité

                # CAS 1 : Le message est pour tous les admins
                if channel_name == "channel:admins":
                    print(f"➡️ [EMIT] Diffusion de l'événement '{event_name}' à la salle 'admins_room'.")
                    await sio.emit(event_name, event_payload, room='admins_room')
                
                # CAS 2 : Le message est pour un utilisateur spécifique
                elif channel_name.startswith("channel:user:"):
                    # On extrait l'ID de l'utilisateur depuis le nom du canal
                    user_id = channel_name.split(":")[-1]
                    
                    # On récupère l'ID de connexion (sid) de cet utilisateur depuis Redis
                    sid = await redis_client.hget("user_connections", user_id)
                    
                    if sid:
                        # Si l'utilisateur est bien connecté, on lui envoie le message
                        print(f"➡️ [EMIT] Envoi de l'événement privé '{event_name}' à user_id {user_id} (SID: {sid}).")
                        await sio.emit(event_name, event_payload, to=sid)
                    else:
                        # Si l'utilisateur n'est pas connecté, on ne fait rien.
                        print(f"⚠️ [WARN] L'utilisateur {user_id} n'est pas connecté, le message privé n'a pas été envoyé.")

            except Exception as e:
                print(f"💥 [REDIS ERROR] Erreur dans l'écouteur: {e}")
                await asyncio.sleep(5)

# --- Montage et Lancement ---
app.mount("/socket.io", socket_app)

if __name__ == "__main__":
    uvicorn.run("main:app", host="127.0.0.1", port=8888, reload=True)