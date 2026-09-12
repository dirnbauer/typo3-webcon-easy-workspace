import { html, nothing } from 'lit';
import { renderRow } from '@webconsulting/webcon-easy-workspace/templates/row.js';

/**
 * A group header (page or news record: icon, title, rootline path,
 * count) followed by its rows. Rendered inside the `role="list"` <ul>,
 * so the header is a presentational <li>.
 */
export function renderGroup(host, group, rowOffset = 0) {
  return html`
    <li class="wew-group" role="presentation" data-wew-group=${group.key}>
      <span class="wew-group__icon" aria-hidden="true">
        <typo3-backend-icon identifier=${group.icon} size="small"></typo3-backend-icon>
      </span>
      <span class="wew-group__text">
        <span class="wew-group__title" title=${group.title}>${group.title}</span>
        ${group.path ? html`<span class="wew-group__path" title=${group.path}>${group.path}</span>` : nothing}
      </span>
      <span class="wew-group__count">${group.rows.length}</span>
    </li>
    ${group.rows.map((item, index) => renderRow(host, item, rowOffset + index))}
  `;
}
