import {LobbyAPI} from './network/LobbyAPI';
import {LobbyController} from './controllers/LobbyController';
import {FriendsController} from './controllers/FriendsController';
import {alertModal} from './utils/modal';

const lobbyRoot = document.getElementById('lobby-root');

if (lobbyRoot) {
    const controller = new LobbyController(new LobbyAPI(), lobbyRoot);
    controller.init();
    window.addEventListener('pagehide', () => controller.dispose(), {once: true});
}

const friendsRoot = document.getElementById('friends-root');

if (friendsRoot) {
    const controller = new FriendsController(new LobbyAPI(), friendsRoot);
    controller.init();
    window.addEventListener('pagehide', () => controller.dispose(), {once: true});
}

// `GET /@/{username}` (05-social.md sec 9.1) - just the five friend/block
// action buttons. No per-row state to manage like the friends page, so a
// lightweight inline handler is proportionate: POST via `LobbyAPI`, then
// reload to reflect the new relationship (this is a low-frequency action
// surface, a full client-side re-render isn't worth the complexity).
const profileRoot = document.getElementById('profile-root');

if (profileRoot) {
    const username = profileRoot.dataset.username;
    const api = new LobbyAPI();

    if (username) {
        profileRoot.addEventListener('click', (event) => {
            const target = event.target;

            if (!(target instanceof HTMLElement)) {
                return;
            }

            const button = target.closest<HTMLElement>('[data-action]');

            if (!button) {
                return;
            }

            const action = button.dataset.action;

            if (!action) {
                return;
            }

            void (async () => {
                try {
                    switch (action) {
                        case 'friend-request':
                            await api.requestFriend(username);
                            break;
                        case 'friend-accept':
                            await api.acceptFriend(username);
                            break;
                        case 'friend-decline':
                            await api.declineFriend(username);
                            break;
                        case 'friend-remove':
                            await api.removeFriend(username);
                            break;
                        case 'friend-block':
                            await api.blockUser(username);
                            break;
                        case 'friend-unblock':
                            await api.unblockUser(username);
                            break;
                        default:
                            return;
                    }

                    window.location.reload();
                } catch (error) {
                    console.error(`Could not complete "${action}":`, error);
                    void alertModal(`Could not complete "${action}".`, 'Error');
                }
            })();
        });
    }
}
