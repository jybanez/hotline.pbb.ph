import { appState, escapeHtml } from './surfaceShared.js';

export function renderGroupedModule(state, config, renderAdminModuleShell) {
    const slice = state[config.stateKey];
    const selectedCategory = slice.categories.find((item) => Number(item.id) === Number(slice.selectedCategoryId))
        ?? slice.categories[0]
        ?? null;
    const categoryCountLabel = `${slice.categories.length} categor${slice.categories.length === 1 ? 'y' : 'ies'}`;

    return renderAdminModuleShell(state, {
        moduleId: config.moduleId,
        title: config.title,
        description: config.description,
        pageClass: `is-${config.moduleId}`,
        bodyClass: 'admin-module-layout is-split',
        bodyContent: slice.loaded ? `
            <div class="admin-grid-host is-standalone" data-admin-${config.gridKey}-grid></div>
            <aside class="panel-card admin-category-panel admin-${config.categoryPanelClass}-panel">
                <div class="admin-category-panel-head">
                    <div>
                        <h2 class="admin-category-panel-title">Categories</h2>
                    </div>
                    <button class="ui-button" type="button" data-admin-${config.categoryActionKey}-category-add>+ New</button>
                </div>
                <div class="admin-category-panel-list" data-admin-${config.categoryActionKey}-category-list></div>
                <div class="admin-category-panel-footer">
                    <div class="admin-category-panel-selection">
                        <span class="pill blue admin-category-panel-count">${escapeHtml(categoryCountLabel)}</span>
                        ${selectedCategory ? `
                            <div class="admin-category-panel-detail">
                                <strong>${escapeHtml(selectedCategory.name ?? 'Untitled')}</strong>
                                <span>${escapeHtml(String(selectedCategory.description ?? '').trim() || 'No description')}</span>
                            </div>
                        ` : ''}
                    </div>
                    <div class="admin-category-panel-actions">
                        <button
                            class="ui-button"
                            type="button"
                            data-admin-${config.categoryActionKey}-category-edit="${selectedCategory?.id ?? ''}"
                            ${selectedCategory ? '' : 'disabled'}
                        >
                            Edit Category
                        </button>
                        <button
                            class="ui-button ui-button-danger"
                            type="button"
                            data-admin-${config.categoryActionKey}-category-delete="${selectedCategory?.id ?? ''}"
                            ${selectedCategory ? '' : 'disabled'}
                        >
                            Delete
                        </button>
                    </div>
                </div>
            </aside>
        ` : `
            <div class="admin-grid-host is-standalone admin-loading-host" data-admin-${config.gridKey}-skeleton></div>
            <aside class="panel-card admin-category-panel admin-${config.categoryPanelClass}-panel">
                <div class="admin-category-panel-head">
                    <div>
                        <h2 class="admin-category-panel-title">Categories</h2>
                    </div>
                    <button class="ui-button" type="button" disabled>+ New</button>
                </div>
                <div class="admin-category-panel-list admin-loading-host" data-admin-${config.categorySkeletonKey}-skeleton></div>
                <div class="admin-category-panel-footer admin-category-panel-footer-loading">
                    <div class="admin-loading-host" data-admin-${config.categorySkeletonKey}-footer-skeleton></div>
                </div>
            </aside>
        `,
    });
}

export function mountCategoryList(root, state, config, deps) {
    const host = root.querySelector(`[data-admin-${config.categoryActionKey}-category-list]`);

    if (!host || !appState.helper.createVirtualList) {
        return;
    }

    const availableHeight = Math.max(220, Math.floor(host.getBoundingClientRect().height || host.clientHeight || 420));
    const slice = state[config.stateKey];
    const rows = slice.categories.map((category) => ({
        ...category,
        item_count: slice.items.filter((item) => Number(config.getItemCategoryId(item)) === Number(category.id)).length,
        isSelected: Number(category.id) === Number(slice.selectedCategoryId),
    }));

    const list = appState.helper.createVirtualList(host, rows, {
        ariaLabel: config.ariaLabel,
        chrome: false,
        height: availableHeight,
        rowHeight: 92,
        overscan: 4,
        emptyText: 'No categories yet.',
        renderItem(item) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `admin-category-item${item.isSelected ? ' is-selected' : ''}`;

            const copy = document.createElement('span');
            copy.className = 'admin-category-item-copy';

            const title = document.createElement('span');
            title.className = 'admin-category-item-title';
            title.textContent = item.name ?? 'Untitled';

            const subtitle = document.createElement('span');
            subtitle.className = 'admin-category-item-subtitle';
            subtitle.textContent = String(item.description ?? '').trim() || 'No description';

            const pill = document.createElement('span');
            pill.className = 'pill blue admin-category-item-pill';
            pill.textContent = config.formatCount(item.item_count);

            copy.append(title, subtitle);
            button.append(copy, pill);
            button.addEventListener('click', () => {
                slice.selectedCategoryId = item.id;
                deps.rerender();
            });

            return button;
        },
    });

    const selectedIndex = rows.findIndex((item) => item.isSelected);

    if (selectedIndex >= 0) {
        list.scrollToIndex(selectedIndex);
    }

    deps.rememberInstance(list);
}
