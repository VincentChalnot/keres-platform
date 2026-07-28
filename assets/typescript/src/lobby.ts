import {LobbyAPI} from './network/LobbyAPI';
import {LobbyController} from './controllers/LobbyController';

const root = document.getElementById('lobby-root');

if (root) {
    const controller = new LobbyController(new LobbyAPI(), root);
    controller.init();
    window.addEventListener('pagehide', () => controller.dispose(), {once: true});
}
