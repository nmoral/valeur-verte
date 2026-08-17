import { Controller } from '@hotwired/stimulus';

/*
 * Garde de confirmation pour une action destructive : placé avant live#action
 * dans data-action, il interrompt l'événement si l'utilisateur annule, empêchant
 * le LiveComponent de déclencher l'action.
 */
export default class extends Controller {
    static values = { message: String };

    ask(event) {
        if (!window.confirm(this.messageValue || 'Confirmer cette action ?')) {
            event.stopImmediatePropagation();
            event.preventDefault();
        }
    }
}
