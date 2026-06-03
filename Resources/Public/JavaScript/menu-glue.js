import { label } from './menu-context.js';

export function isCompactToolbar(host) {
  return !host._config.showSubelementsInToolbar;
}

export function friendlyTable(host, table) {
  switch (table) {
    case 'pages': return label(host, 'table.pages');
    case 'tt_content': return label(host, 'table.tt_content');
    case 'tx_news_domain_model_news': return label(host, 'table.tx_news_domain_model_news');
    case 'sys_file_metadata': return label(host, 'table.sys_file_metadata');
    case 'tt_address': return label(host, 'table.tt_address');
    default: return table;
  }
}
