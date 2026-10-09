/**
 * A small interpreter for the ICU MessageFormat subset the catalogues use
 * (the same subset `App\Service\Translation\IcuArguments` validates on the
 * PHP side, so a message that passes `bin/console app:translations:check`
 * renders here):
 *
 *   - `{name}`                      simple argument (numbers are formatted for the locale)
 *   - `{n, number}`                 number formatted for the locale
 *   - `{n, plural, =0 {…} one {…} other {…}}`   with `#` standing for the number
 *   - `{side, select, white {…} other {…}}`
 *   - apostrophe quoting: `''` is an apostrophe, `'{` … `'` quotes literally;
 *     any other lone apostrophe is literal (so French `l’` / `l'` both work).
 *
 * Messages are parsed once and cached.
 */

export type MessageParams = Record<string, string | number>;

type Node =
    | string
    | {kind: 'arg'; name: string}
    | {kind: 'number'; name: string}
    | {kind: 'hash'}
    | {kind: 'plural'; name: string; branches: Array<[string, Node[]]>}
    | {kind: 'select'; name: string; branches: Array<[string, Node[]]>};

const parsed = new Map<string, Node[]>();

class Parser {
    private position = 0;

    constructor(private readonly message: string) {}

    parse(): Node[] {
        const nodes = this.sequence(false);

        if (this.position < this.message.length) {
            throw new Error(`Unmatched "}" at offset ${this.position}`);
        }

        return nodes;
    }

    private sequence(nested: boolean): Node[] {
        const nodes: Node[] = [];
        let text = '';
        const flush = (): void => {
            if (text !== '') {
                nodes.push(text);
                text = '';
            }
        };

        while (this.position < this.message.length) {
            const char = this.message[this.position];

            if (char === "'") {
                text += this.quote(nested);
            } else if (char === '{') {
                this.position++;
                flush();
                nodes.push(this.argument());
            } else if (char === '}') {
                if (nested) {
                    flush();

                    return nodes;
                }

                throw new Error(`Unmatched "}" at offset ${this.position}`);
            } else if (char === '#' && nested) {
                this.position++;
                flush();
                nodes.push({kind: 'hash'});
            } else {
                text += char;
                this.position++;
            }
        }

        if (nested) {
            throw new Error('Unterminated plural/select branch');
        }

        flush();

        return nodes;
    }

    private quote(nested: boolean): string {
        const next = this.message[this.position + 1] ?? '';

        if (next === "'") {
            this.position += 2;

            return "'";
        }

        if (next !== '{' && next !== '}' && !(nested && next === '#')) {
            this.position++;

            return "'";
        }

        this.position += 2;
        let literal = next;

        while (this.position < this.message.length) {
            const char = this.message[this.position];

            if (char === "'") {
                if (this.message[this.position + 1] === "'") {
                    literal += "'";
                    this.position += 2;

                    continue;
                }

                this.position++;

                return literal;
            }

            literal += char;
            this.position++;
        }

        return literal;
    }

    private argument(): Node {
        const name = this.word();

        if (name === '') {
            throw new Error(`Missing argument name at offset ${this.position}`);
        }

        this.skipSpaces();

        if (this.consume('}')) {
            return {kind: 'arg', name};
        }

        if (!this.consume(',')) {
            throw new Error(`Expected "," or "}" after "${name}"`);
        }

        this.skipSpaces();
        const type = this.word();
        this.skipSpaces();

        if (type === 'number') {
            if (this.consume(',')) {
                while (this.position < this.message.length && this.message[this.position] !== '}') {
                    this.position++;
                }
            }

            if (!this.consume('}')) {
                throw new Error(`Unterminated number argument "${name}"`);
            }

            return {kind: 'number', name};
        }

        if (type !== 'plural' && type !== 'select') {
            throw new Error(`Unsupported argument type "${type}" for "${name}"`);
        }

        if (!this.consume(',')) {
            throw new Error(`Expected "," after "${name}, ${type}"`);
        }

        const branches: Array<[string, Node[]]> = [];

        for (;;) {
            this.skipSpaces();

            if (this.consume('}')) {
                break;
            }

            const selector = this.selector();
            this.skipSpaces();

            if (selector === '' || !this.consume('{')) {
                throw new Error(`Expected a selector and "{" in "${name}, ${type}"`);
            }

            branches.push([selector, this.sequence(true)]);
            this.consume('}');
        }

        if (!branches.some(([selector]) => selector === 'other')) {
            throw new Error(`"${name}, ${type}" has no "other" branch`);
        }

        return {kind: type, name, branches};
    }

    private word(): string {
        const start = this.position;

        while (/[A-Za-z0-9_]/.test(this.message[this.position] ?? '')) {
            this.position++;
        }

        return this.message.slice(start, this.position);
    }

    private selector(): string {
        const start = this.position;

        while (this.position < this.message.length && !/[\s{}]/.test(this.message[this.position])) {
            this.position++;
        }

        return this.message.slice(start, this.position);
    }

    private skipSpaces(): void {
        while (/\s/.test(this.message[this.position] ?? '')) {
            this.position++;
        }
    }

    private consume(char: string): boolean {
        if (this.message[this.position] !== char) {
            return false;
        }

        this.position++;

        return true;
    }
}

function parseMessage(message: string): Node[] {
    let nodes = parsed.get(message);

    if (nodes === undefined) {
        nodes = new Parser(message).parse();
        parsed.set(message, nodes);
    }

    return nodes;
}

function render(nodes: Node[], params: MessageParams, locale: string, count: number | null): string {
    let out = '';

    for (const node of nodes) {
        if (typeof node === 'string') {
            out += node;
            continue;
        }

        switch (node.kind) {
            case 'hash':
                out += new Intl.NumberFormat(locale).format(count ?? 0);
                break;
            case 'arg': {
                const value = params[node.name];
                out += value === undefined ? `{${node.name}}` : typeof value === 'number' ? new Intl.NumberFormat(locale).format(value) : value;
                break;
            }
            case 'number': {
                const value = Number(params[node.name]);
                out += new Intl.NumberFormat(locale).format(Number.isFinite(value) ? value : 0);
                break;
            }
            case 'select': {
                const value = String(params[node.name] ?? '');
                const branch = node.branches.find(([selector]) => selector === value) ?? node.branches.find(([selector]) => selector === 'other');
                out += branch ? render(branch[1], params, locale, count) : '';
                break;
            }
            case 'plural': {
                const value = Number(params[node.name]);
                const n = Number.isFinite(value) ? value : 0;
                const category = new Intl.PluralRules(locale).select(n);
                const branch = node.branches.find(([selector]) => selector === `=${n}`)
                    ?? node.branches.find(([selector]) => selector === category)
                    ?? node.branches.find(([selector]) => selector === 'other');
                out += branch ? render(branch[1], params, locale, n) : '';
                break;
            }
        }
    }

    return out;
}

/** Renders an ICU-subset message with its parameters for a locale. */
export function formatMessage(message: string, params: MessageParams, locale: string): string {
    return render(parseMessage(message), params, locale, null);
}
