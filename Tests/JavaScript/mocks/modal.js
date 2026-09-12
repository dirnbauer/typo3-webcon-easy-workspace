export const Sizes = { small: 'small', medium: 'medium', large: 'large', expand: 'expand' };
export const Types = { default: 'default', ajax: 'ajax', iframe: 'iframe' };
export const Positions = { center: 'center', sheet: 'sheet' };

function fakeModal() {
  const element = document.createElement('div');
  element.hideModal = () => element.dispatchEvent(new Event('typo3-modal-hidden'));
  return element;
}

export default {
  confirm: () => fakeModal(),
  advanced: () => fakeModal(),
};
