/**
 * E-Mail-Adresse im üblichen Format «name@firma.ch» — dieselbe Prüfung wie `Rules\EmailAddress` auf dem Server: ein
 * Teil vor dem «@», eine Domain mit Punkt und einer Endung aus mindestens zwei Buchstaben, ohne Leerzeichen und ohne
 * doppelte Punkte; Umlaute in der Domain erlaubt. Leerzeichen am Rand zählen nicht.
 */
const LOCAL =
    /^[\p{L}\p{N}!#$%&'*+/=?^_`{|}~-]+(\.[\p{L}\p{N}!#$%&'*+/=?^_`{|}~-]+)*$/u;
const LABEL = /^[\p{L}\p{N}]([\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?$/u;
const TLD = /^(\p{L}{2,63}|xn--[a-z0-9-]{2,59})$/iu;

export function isEmailAddress(value: string): boolean {
    const address = value.trim();
    if (address === '' || address.length > 254 || /\s/u.test(address)) {
        return false;
    }
    const parts = address.split('@');
    if (parts.length !== 2) {
        return false;
    }
    const [local = '', domain = ''] = parts;
    if (local === '' || local.length > 64 || !LOCAL.test(local)) {
        return false;
    }
    const labels = domain.split('.');
    if (labels.length < 2 || !labels.every((label) => LABEL.test(label))) {
        return false;
    }

    return TLD.test(labels[labels.length - 1] ?? '');
}
