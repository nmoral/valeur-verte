import { Controller } from '@hotwired/stimulus';

/*
 * « Tout sélectionner » d'une liste : coche/décoche toutes les cases de ligne et
 * déclenche l'événement change sur chacune pour que le LiveComponent mette à jour
 * sa propriété `selection`.
 */
export default class extends Controller {
    static targets = ['case'];

    toggle(event) {
        const coche = event.currentTarget.checked;
        this.caseTargets.forEach((c) => {
            if (c.checked !== coche) {
                c.checked = coche;
                c.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }
}
