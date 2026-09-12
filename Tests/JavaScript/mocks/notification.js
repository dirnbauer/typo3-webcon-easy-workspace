export const __notifications = [];
const record = (severity) => (title, message, duration) => {
  __notifications.push({ severity, title, message, duration });
};
export default {
  success: record('success'),
  info: record('info'),
  warning: record('warning'),
  error: record('error'),
  notice: record('notice'),
};
