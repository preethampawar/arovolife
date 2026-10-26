// Visual editor for the legal pages (terms, ethics, privacy, grievance, the
// Compensation Plan…), R-125.
//
// Trix, used for blogs and news, knows one heading size and no tables, and
// rewrote these pages the moment it opened them. Tiptap keeps both, so an
// administrator edits the page as it looks rather than as HTML.
//
// The hidden `body` input keeps the stored text until the document actually
// changes. Tiptap re-serialises the page (cells wrapped in <p>, no <thead>),
// and sending that back on a save with no edits would change the text every
// distributor agreed to. The controller checks the same thing server-side.
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { TableKit } from '@tiptap/extension-table';

const commands = {
    paragraph: (chain) => chain.setParagraph(),
    heading: (chain) => chain.toggleHeading({ level: 2 }),
    subheading: (chain) => chain.toggleHeading({ level: 3 }),
    smallheading: (chain) => chain.toggleHeading({ level: 4 }),
    bold: (chain) => chain.toggleBold(),
    italic: (chain) => chain.toggleItalic(),
    underline: (chain) => chain.toggleUnderline(),
    bulletList: (chain) => chain.toggleBulletList(),
    orderedList: (chain) => chain.toggleOrderedList(),
    blockquote: (chain) => chain.toggleBlockquote(),
    horizontalRule: (chain) => chain.setHorizontalRule(),
    unlink: (chain) => chain.extendMarkRange('link').unsetLink(),
    insertTable: (chain) => chain.insertTable({ rows: 3, cols: 3, withHeaderRow: true }),
    addRowAfter: (chain) => chain.addRowAfter(),
    addColumnAfter: (chain) => chain.addColumnAfter(),
    deleteRow: (chain) => chain.deleteRow(),
    deleteColumn: (chain) => chain.deleteColumn(),
    deleteTable: (chain) => chain.deleteTable(),
    undo: (chain) => chain.undo(),
    redo: (chain) => chain.redo(),
};

// Which toolbar buttons show as "on" for the current selection.
const activeWhen = {
    paragraph: (editor) => editor.isActive('paragraph'),
    heading: (editor) => editor.isActive('heading', { level: 2 }),
    subheading: (editor) => editor.isActive('heading', { level: 3 }),
    smallheading: (editor) => editor.isActive('heading', { level: 4 }),
    bold: (editor) => editor.isActive('bold'),
    italic: (editor) => editor.isActive('italic'),
    underline: (editor) => editor.isActive('underline'),
    bulletList: (editor) => editor.isActive('bulletList'),
    orderedList: (editor) => editor.isActive('orderedList'),
    blockquote: (editor) => editor.isActive('blockquote'),
    link: (editor) => editor.isActive('link'),
};

// Buttons that only make sense with the cursor inside a table.
const tableOnly = ['addRowAfter', 'addColumnAfter', 'deleteRow', 'deleteColumn', 'deleteTable'];

function promptForLink(editor) {
    const current = editor.getAttributes('link').href ?? '';
    const href = window.prompt('Link address (for example /p/terms or https://…). Leave empty to remove the link.', current);

    if (href === null) {
        return;
    }

    const chain = editor.chain().focus().extendMarkRange('link');
    (href.trim() === '' ? chain.unsetLink() : chain.setLink({ href: href.trim() })).run();
}

function mount(root) {
    const input = document.getElementById(root.dataset.input);
    const surface = root.querySelector('[data-policy-surface]');
    const buttons = root.querySelectorAll('[data-cmd]');

    const editor = new Editor({
        element: surface,
        content: input.value,
        extensions: [
            StarterKit.configure({
                heading: { levels: [1, 2, 3, 4] },
                // The legal pages' cleaning profile keeps no <s>.
                strike: false,
                link: { openOnClick: false, autolink: false, HTMLAttributes: { target: null, rel: null } },
            }),
            TableKit.configure({ table: { resizable: false } }),
        ],
        editorProps: {
            attributes: { class: 'page-body policy-editor-surface', 'aria-label': 'Page text', role: 'textbox', 'aria-multiline': 'true' },
        },
        onUpdate: ({ editor: updated }) => {
            input.value = updated.getHTML();
        },
        onTransaction: ({ editor: current }) => refresh(current),
    });

    // Takes the editor from the callback: the first transaction can fire
    // before the constructor has returned and `editor` is assigned.
    function refresh(current) {
        const inTable = current.isActive('table');

        buttons.forEach((button) => {
            const name = button.dataset.cmd;
            const isActive = activeWhen[name]?.(current) ?? false;

            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');

            if (tableOnly.includes(name)) {
                button.disabled = ! inTable;
            }
        });
    }

    buttons.forEach((button) => {
        button.addEventListener('click', () => {
            const name = button.dataset.cmd;

            if (name === 'link') {
                promptForLink(editor);

                return;
            }

            commands[name]?.(editor.chain().focus()).run();
        });
    });

    refresh(editor);
    root.dataset.ready = 'true';
}

document.querySelectorAll('[data-policy-editor]').forEach(mount);
