import axios from '../bootstrap';
export async function getCsrfCookie() {
    await axios.get('/sanctum/csrf-cookie');
}
export async function login(credentials) {
    await getCsrfCookie();
    const response = await axios.post('/api/login', credentials);
    return response.data.data.user;
}
export async function logout() {
    const response = await axios.post('/api/logout');
    return response.data;
}
export async function getCurrentUser() {
    const response = await axios.get('/api/me');
    return response.data.data.user;
}
export async function getDashboard() {
    const response = await axios.get('/api/dashboard');
    return response.data.data;
}
export async function getClasses(params = {}) {
    const response = await axios.get('/api/classes', { params });
    return response.data.data;
}
export async function getStudents(params = {}) {
    const response = await axios.get('/api/students', { params });
    return response.data.data;
}
export async function getTeachers(params = {}) {
    const response = await axios.get('/api/teachers', { params });
    return response.data.data;
}
export async function getTeacherClasses(params = {}) {
    const response = await axios.get('/api/teacher/classes', { params });
    return response.data.data;
}
export async function getClassAssignments(params = {}) {
    const response = await axios.get('/api/class-assignments', { params });
    return response.data.data;
}
export async function getClassStudents(classId, params = {}) {
    const response = await axios.get(`/api/classes/${classId}/students`, { params });
    return response.data.data;
}
export async function getAttendance(params = {}) {
    const response = await axios.get('/api/attendance', { params });
    return response.data.data;
}
export async function getAttendanceReport(params = {}) {
    const response = await axios.get('/api/reports/attendance', { params });
    return response.data.data;
}
export async function createStudent(payload) {
    const response = await axios.post('/api/students', payload);
    return response.data.data;
}
export async function updateStudent(id, payload) {
    const response = await axios.put(`/api/students/${id}`, payload);
    return response.data.data;
}
export async function deleteStudent(id) {
    const response = await axios.delete(`/api/students/${id}`);
    return response.data;
}
export async function createClass(payload) {
    const response = await axios.post('/api/classes', payload);
    return response.data.data;
}
export async function updateClass(id, payload) {
    const response = await axios.put(`/api/classes/${id}`, payload);
    return response.data.data;
}
export async function deleteClass(id) {
    const response = await axios.delete(`/api/classes/${id}`);
    return response.data;
}
export async function createTeacher(payload) {
    const response = await axios.post('/api/teachers', payload);
    return response.data.data;
}
export async function updateTeacher(id, payload) {
    const response = await axios.put(`/api/teachers/${id}`, payload);
    return response.data.data;
}
export async function deleteTeacher(id) {
    const response = await axios.delete(`/api/teachers/${id}`);
    return response.data;
}
export async function createClassAssignment(payload) {
    const response = await axios.post('/api/class-assignments', payload);
    return response.data.data;
}
export async function deleteClassAssignment(id) {
    const response = await axios.delete(`/api/class-assignments/${id}`);
    return response.data;
}
export async function createAttendance(payload) {
    const response = await axios.post('/api/attendance', payload);
    return response.data.data;
}
export async function updateAttendance(id, payload) {
    const response = await axios.put(`/api/attendance/${id}`, payload);
    return response.data.data;
}
export async function deleteAttendance(id) {
    const response = await axios.delete(`/api/attendance/${id}`);
    return response.data;
}